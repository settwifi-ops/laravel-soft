<?php

namespace App\Services;

use App\Models\User;
use App\Models\AiDecision;
use App\Models\UserPosition;
use App\Models\TradeHistory;
use App\Models\UserPortfolio;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\RegimeSummary;     // ✅ TAMBAH BARIS INI
use Carbon\Carbon;
use App\Events\TradingExecutedEvent;

class TradingExecutionService
{
    private $binanceService;
    private $currentDecision; 

    public function __construct(BinanceService $binanceService)
    {
        $this->binanceService = $binanceService;
    }

    /**
     * Execute trading decision for all enabled users
     */
    public function executeDecision(AiDecision $decision)
    {
        $this->currentDecision = $decision; // ✅ TAMBAH BARIS INI
        
        if ($decision->executed) {
            Log::info("Decision {$decision->id} already executed");
            $this->currentDecision = null; // ✅ TAMBAH BARIS INI (cleanup)
            return;
        }

        // ✅ NEW: REGIME VALIDATION
        $regimeValidation = $this->validateWithRegime($decision);
        if (!$regimeValidation['valid']) {
            Log::warning("❌ Regime validation failed for {$decision->symbol}: " . $regimeValidation['reason']);
            $decision->update([
                'executed' => true, 
                'status' => 'REJECTED',
                'explanation' => $decision->explanation . " [REJECTED: " . $regimeValidation['reason'] . "]"
            ]);
            return;
        }

        // ✅ NEW: DECISION EXPIRY (30 minutes)
        if ($decision->created_at->diffInMinutes(now()) > 30) {
            Log::warning("🕒 Decision {$decision->id} expired - created at: " . $decision->created_at);
            $decision->update([
                'executed' => true, 
                'status' => 'EXPIRED'
            ]);
            return;
        }

        if ($decision->action === 'HOLD') {
            $decision->update(['executed' => true]);
            Log::info("HOLD decision, skipping execution");
            return;
        }

        $enabledUsers = User::whereHas('portfolio', function($query) {
            $query->where('ai_trade_enabled', true)
                  ->where('equity', '>', 0);
        })->get();

        Log::info("🎯 Executing {$decision->action} {$decision->symbol} for " . $enabledUsers->count() . " users");

        if ($enabledUsers->count() === 0) {
            Log::warning("❌ No users with AI Trade enabled found!");
            $decision->update(['executed' => true]);
            return;
        }

        $successCount = 0;
        foreach ($enabledUsers as $user) {
            try {
                DB::transaction(function () use ($user, $decision, &$successCount) {
                    $executed = $this->executeForUser($user, $decision);
                    if ($executed) {
                        $successCount++;
                        $this->notifyUserTradeExecution(
                            $user->id,
                            $decision->symbol,
                            $decision->action,
                            "Your {$this->getPositionTypeFromAction($decision->action)} position for {$decision->symbol} has been opened"
                        );
                    }
                });
            } catch (\Exception $e) {
                Log::error("Execution failed for user {$user->id}: " . $e->getMessage());
                $this->notifyUserTradeError(
                    $user->id,
                    $decision->symbol,
                    $decision->action,
                    "Failed to execute {$decision->action} for {$decision->symbol}: " . $e->getMessage()
                );
            }
        }

        $decision->update(['executed' => true]);
        
        if ($successCount > 0) {
            event(new TradingExecutedEvent(
                $decision->symbol,
                $decision->action,
                "Executed {$decision->action} for {$decision->symbol} - {$successCount}/{$enabledUsers->count()} users",
                $successCount,
                $enabledUsers->count()
            ));
        }

        Log::info("✅ Successfully executed for {$successCount}/{$enabledUsers->count()} users");
        $this->currentDecision = null; // ✅ TAMBAH BARIS INI (cleanup)
    }   
    private function notifyUserTradeExecution($userId, $symbol, $action, $message)
    {
         // ✅ DEBUG: LOG SEBELUM EVENT
        Log::info("🔔 BEFORE EVENT - User: {$userId}, Symbol: {$symbol}, Action: {$action}");
        try {
            // Existing event
            event(new TradingExecutedEvent(
                $userId,
                "Trade Executed - {$symbol}",
                $message,
                strtolower($action),
                [
                    'symbol' => $symbol,
                    'action' => $action,
                    'type' => 'trade_execution',
                    'timestamp' => now()->toDateTimeString()
                ]
            ));

            // ✅ TAMBAHKAN INI: Pusher real-time notification
            $pusher = new \Pusher\Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'useTLS' => true
                ]
            );

            $notificationData = [
                'id' => uniqid(),
                'title' => "🎯 Trade Executed - {$symbol}",
                'message' => $message,
                'type' => strtolower($action),
                'data' => [
                    'symbol' => $symbol,
                    'action' => $action,
                    'type' => 'trade_execution',
                    'timestamp' => now()->toISOString()
                ],
                'received_at' => now()->toISOString()
            ];

            $pusher->trigger("private-user-{$userId}", 'new.signal', $notificationData);
            Log::info("📢 PUSHER NOTIFICATION: Trade execution sent to user {$userId}");

        } catch (\Exception $e) {
            Log::error("❌ Pusher notification failed: " . $e->getMessage());
        }
    }

    /**
     * Execute trading decision for specific user
     */
    public function executeForUser(User $user, AiDecision $decision)
    {
        $portfolio = $user->portfolio;
        
        if (!$portfolio || !$portfolio->ai_trade_enabled) {
            Log::warning("User {$user->id} has no portfolio or AI trading disabled");
            return false;
        }
        
        // Check available balance instead of total balance
        $availableBalance = $portfolio->available_balance;
        if ($availableBalance <= 0) {
            Log::warning("User {$user->id} has insufficient available balance: {$availableBalance}");
            return false;
        }

        $positionType = $this->getPositionTypeFromAction($decision->action);
        
        if ($decision->action === 'BUY') {
            return $this->executeBuy($user, $portfolio, $decision, $positionType);
        } elseif ($decision->action === 'SELL') {
            return $this->executeSell($user, $portfolio, $decision, $positionType);
        }

        return false;
    }

    /**
     * Convert AI action to position type
     */
    private function getPositionTypeFromAction($action)
    {
        return $action === 'BUY' ? 'LONG' : 'SHORT';
    }


    /**
     * Execute BUY action (open LONG/SHORT position)
     */
    private function executeBuy(User $user, $portfolio, AiDecision $decision, $positionType)
    {
        // Check opposite position
        $oppositePosition = UserPosition::where('user_id', $user->id)
            ->where('symbol', $decision->symbol)
            ->where('status', 'OPEN')
            ->where('position_type', '!=', $positionType)
            ->first();

        if ($oppositePosition) {
            Log::info("🔄 User {$user->id} has opposite position for {$decision->symbol}. Closing {$oppositePosition->position_type} first...");
            
            $currentPrice = $this->binanceService->getCurrentPrice(
                str_replace('USDT', '', $decision->symbol)
            );
            
            if ($currentPrice && $currentPrice > 0) {
                $this->closePosition($oppositePosition, $currentPrice, "Auto-close for new {$positionType} position");
            } else {
                Log::error("❌ Failed to get price for closing opposite position");
                return false;
            }
        }

        // Check existing same position
        $existingSamePosition = UserPosition::where('user_id', $user->id)
            ->where('symbol', $decision->symbol)
            ->where('status', 'OPEN')
            ->where('position_type', $positionType)
            ->first();

        if ($existingSamePosition) {
            Log::info("User {$user->id} already has open {$positionType} position for {$decision->symbol}, skipping");
            return false;
        }

        // Limit open positions
        $openPositionsCount = UserPosition::where('user_id', $user->id)
            ->where('status', 'OPEN')
            ->count();

        if ($openPositionsCount >= 10) {
            Log::warning("User {$user->id} has maximum open positions (10), skipping new {$positionType}");
            return false;
        }

        // Calculate position size based on AVAILABLE balance
        $availableBalance = $portfolio->available_balance;
        // ✅ UPDATED: Dynamic risk amount dengan regime data
        $riskAmount = $this->calculateRiskAmount($portfolio, $decision->confidence, $decision->symbol);
        
        // Final check dengan available balance
        if (!$portfolio->canOpenPosition($riskAmount)) {
            Log::warning("Insufficient available balance for user {$user->id}. Available: \${$availableBalance}, Required: \${$riskAmount}");
            return false;
        }

        // Get current price
        $currentPrice = $this->binanceService->getCurrentPrice(
            str_replace('USDT', '', $decision->symbol)
        );

        if (!$currentPrice || $currentPrice <= 0) {
            Log::error("Failed to get valid current price for {$decision->symbol}");
            return false;
        }

        // Calculate quantity
        $quantity = $riskAmount / $currentPrice;

        if ($quantity <= 0) {
            Log::error("Invalid quantity calculated: {$quantity} for amount: {$riskAmount} and price: {$currentPrice}");
            return false;
        }

        try {
            // ✅ UPDATED: Dynamic SL/TP based on volatility
            list($stopLoss, $takeProfit) = $this->calculateDynamicSLTP($currentPrice, $positionType, $decision->symbol);

            // Create position - BALANCE TIDAK BERUBAH!
            $position = UserPosition::create([
                'user_id' => $user->id,
                'portfolio_id' => $portfolio->id,
                'ai_decision_id' => $decision->id,
                'symbol' => $decision->symbol,
                'position_type' => $positionType,
                'qty' => $quantity,
                'avg_price' => $currentPrice,
                'current_price' => $currentPrice,
                'investment' => $riskAmount,
                'floating_pnl' => 0,
                'pnl_percentage' => 0,
                'stop_loss' => $stopLoss,
                'take_profit' => $takeProfit,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);

            // ✅ BALANCE TIDAK DIKURANGI! Hanya update equity
            $portfolio->calculateEquity();

            // Create trade history
            TradeHistory::create([
                'user_id' => $user->id,
                'ai_decision_id' => $decision->id,
                'position_id' => $position->id,
                'symbol' => $decision->symbol,
                'action' => 'BUY',
                'position_type' => $positionType,
                'qty' => $quantity,
                'price' => $currentPrice,
                'amount' => $riskAmount,
                'notes' => "AI {$positionType} Trade - Confidence: {$decision->confidence}% - Risk: {$portfolio->risk_value}% - Available Balance: \${$availableBalance}",
            ]);

            Log::info("✅ {$positionType} BUY executed for user {$user->id}: {$quantity} {$decision->symbol} at \${$currentPrice} for \${$riskAmount}. Available Balance: \${$availableBalance}");
            return true;

        } catch (\Exception $e) {
            Log::error("BUY execution failed for user {$user->id}: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Calculate dynamic Stop Loss and Take Profit based on volatility
     */
    private function calculateDynamicSLTP($entryPrice, $positionType, $symbol)
    {
        // Get regime data for volatility
        $regime = \App\Models\MarketRegime::where('symbol', $symbol)
            ->orderBy('timestamp', 'desc')
            ->first();
            
        if (!$regime) {
            Log::warning("⚠️ No regime data for SL/TP calculation - using conservative defaults");
            // Conservative defaults
            $stopLoss = $positionType === 'LONG' ? $entryPrice * 0.97 : $entryPrice * 1.03;
            $takeProfit = $positionType === 'LONG' ? $entryPrice * 1.06 : $entryPrice * 0.94;
            return [round($stopLoss, 4), round($takeProfit, 4)];
        }

        $volatility = $regime->volatility_24h;
        $regimeType = $regime->regime;

        // Base SL distance on volatility (more volatile = wider stops)
        $baseSLDistance = $volatility * 1.5; // 1.5x daily volatility
        
        // Adjust based on regime
        $regimeMultiplier = match($regimeType) {
            'bull' => $positionType === 'LONG' ? 0.8 : 1.2,   // Tighter SL for LONG in bull, wider for SHORT
            'bear' => $positionType === 'SHORT' ? 0.8 : 1.2,  // Tighter SL for SHORT in bear, wider for LONG
            'neutral' => 1.0,
            'reversal' => 1.3, // Wider SL in reversal (higher uncertainty)
            default => 1.0
        };

        $finalSLDistance = $baseSLDistance * $regimeMultiplier;
        $finalTPDistance = $finalSLDistance * 2; // 1:2 risk-reward ratio

        // Apply bounds (min 1%, max 10% for SL)
        $finalSLDistance = max(0.01, min(0.10, $finalSLDistance));
        $finalTPDistance = max(0.02, min(0.20, $finalTPDistance));

        if ($positionType === 'LONG') {
            $stopLoss = $entryPrice * (1 - $finalSLDistance);
            $takeProfit = $entryPrice * (1 + $finalTPDistance);
        } else { // SHORT
            $stopLoss = $entryPrice * (1 + $finalSLDistance);
            $takeProfit = $entryPrice * (1 - $finalTPDistance);
        }

        Log::info("🎯 Dynamic SL/TP for {$symbol}:", [
            'entry_price' => $entryPrice,
            'position_type' => $positionType,
            'volatility' => round($volatility * 100, 2) . '%',
            'sl_distance' => round($finalSLDistance * 100, 2) . '%',
            'tp_distance' => round($finalTPDistance * 100, 2) . '%',
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'risk_reward' => '1:' . round($finalTPDistance / $finalSLDistance, 1)
        ]);

        return [round($stopLoss, 4), round($takeProfit, 4)];
    }
    /**
     * Execute SELL action (open SHORT position)
     */
    private function executeSell(User $user, $portfolio, AiDecision $decision, $positionType)
    {
        return $this->executeBuy($user, $portfolio, $decision, $positionType);
    }

    /**
     * Calculate Stop Loss dan Take Profit
     */
    private function calculateSLTP($entryPrice, $positionType, $riskMode)
    {
        $slPercent = 0;
        $tpPercent = 0;

        switch ($riskMode) {
            case 'CONSERVATIVE':
                $slPercent = 0.02; // 2%
                $tpPercent = 0.04; // 4%
                break;
            case 'MODERATE':
                $slPercent = 0.03; // 3%
                $tpPercent = 0.06; // 6%
                break;
            case 'AGGRESSIVE':
                $slPercent = 0.05; // 5%
                $tpPercent = 0.10; // 10%
                break;
        }

        if ($positionType === 'LONG') {
            $stopLoss = $entryPrice * (1 - $slPercent);
            $takeProfit = $entryPrice * (1 + $tpPercent);
        } else { // SHORT
            $stopLoss = $entryPrice * (1 + $slPercent);
            $takeProfit = $entryPrice * (1 - $tpPercent);
        }

        return [
            round($stopLoss, 4),
            round($takeProfit, 4)
        ];
    }

    /**
     * Update floating PnL untuk semua open positions
     */
    public function updateAllFloatingPnL()
    {
        $openPositions = UserPosition::where('status', 'OPEN')->get();

        $updatedCount = 0;
        
        foreach ($openPositions as $position) {
            try {
                $currentPrice = $this->binanceService->getCurrentPrice(
                    str_replace('USDT', '', $position->symbol)
                );
                
                if ($currentPrice && $currentPrice > 0) {
                    $position->updateFloatingPnl($currentPrice);
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                Log::error("Failed to update PNL for position {$position->id}: " . $e->getMessage());
            }
        }

        // Update semua portfolio equity
        $portfolios = UserPortfolio::has('user')->get();
        foreach ($portfolios as $portfolio) {
            $portfolio->calculateEquity();
        }

        Log::info("✅ Floating PNL updated for {$updatedCount} positions");
        return $updatedCount;
    }

    /**
     * Auto close positions berdasarkan SL/TP rules
     */
    public function autoClosePositions()
    {
        $openPositions = UserPosition::where('status', 'OPEN')->get();

        $closedCount = 0;
        $totalPnl = 0;

        foreach ($openPositions as $position) {
            try {
                $currentPrice = $this->binanceService->getCurrentPrice(
                    str_replace('USDT', '', $position->symbol)
                );

                if (!$currentPrice || $currentPrice <= 0) continue;

                // Update PNL terlebih dahulu
                $position->updateFloatingPnl($currentPrice);

                // Check auto-close conditions
                $shouldClose = $this->shouldAutoClosePosition($position, $currentPrice);

                if ($shouldClose['close']) {
                    $closed = $this->closePosition($position, $currentPrice, $shouldClose['reason']);
                    if ($closed) {
                        $closedCount++;
                        $totalPnl += $position->floating_pnl;
                    }
                }

            } catch (\Exception $e) {
                Log::error("Auto-close failed for position {$position->id}: " . $e->getMessage());
            }
        }

        Log::info("✅ Auto-close completed: {$closedCount} positions closed, Total PNL: \${$totalPnl}");
        return ['closed' => $closedCount, 'total_pnl' => $totalPnl];
    }

    /**
     * Close position dengan reason tertentu - DENGAN NOTIFIKASI
     */
    public function closePosition(UserPosition $position, $currentPrice, $reason)
    {
        try {
            return DB::transaction(function () use ($position, $currentPrice, $reason) {
                $portfolio = $position->portfolio;
                
                // Calculate final PnL
                if ($position->position_type === 'LONG') {
                    $pnl = ($currentPrice - $position->avg_price) * $position->qty;
                } else {
                    $pnl = ($position->avg_price - $currentPrice) * $position->qty;
                }

                $pnlPercentage = ($pnl / $position->investment) * 100;

                Log::info("🔧 Close Position - AVAILABLE BALANCE SYSTEM", [
                    'position_id' => $position->id,
                    'investment' => $position->investment,
                    'pnl' => $pnl,
                    'old_balance' => $portfolio->balance,
                    'new_balance' => $portfolio->balance + $pnl
                ]);

                // Update position
                $position->update([
                    'status' => 'CLOSED',
                    'current_price' => $currentPrice,
                    'exit_price' => $currentPrice,
                    'floating_pnl' => $pnl,
                    'realized_pnl' => $pnl,
                    'pnl_percentage' => $pnlPercentage,
                    'closed_at' => now(),
                    'close_reason' => $reason
                ]);

                // ✅ FIXED: Hanya tambahkan PnL ke balance menggunakan method yang aman
                $portfolio->addPnl($pnl);

                // Create trade history (amount hanya untuk record)
                TradeHistory::create([
                    'user_id' => $position->user_id,
                    'ai_decision_id' => $position->ai_decision_id,
                    'position_id' => $position->id,
                    'symbol' => $position->symbol,
                    'action' => 'SELL',
                    'position_type' => $position->position_type,
                    'qty' => $position->qty,
                    'price' => $currentPrice,
                    'amount' => $position->investment + $pnl, // ✅ Hanya untuk record
                    'pnl' => $pnl,
                    'pnl_percentage' => $pnlPercentage,
                    'notes' => "CLOSE: " . $reason,
                ]);

                // NOTIFIKASI: Trigger untuk position closed
                $this->notifyUserPositionClosed(
                    $position->user_id,
                    $position->symbol,
                    $position->position_type,
                    $pnl,
                    $reason
                );

                Log::info("🔒 CLOSE: {$position->symbol} {$position->position_type} - PNL: \${$pnl} - Reason: {$reason} - New Available Balance: \${$portfolio->available_balance}");

                return true;
            });

        } catch (\Exception $e) {
            Log::error("Close position failed for {$position->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Close position manually untuk user tertentu - DENGAN NOTIFIKASI
     */
    public function closePositionManually($positionId, $userId = null, $reason = "Manual Close")
    {
        try {
            return DB::transaction(function () use ($positionId, $userId, $reason) {
                // Find position
                $query = UserPosition::where('id', $positionId)
                    ->where('status', 'OPEN');
                
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                
                $position = $query->first();

                if (!$position) {
                    throw new \Exception("Position not found or already closed");
                }

                // Get current price
                $symbol = str_replace('USDT', '', $position->symbol);
                $currentPrice = $this->getClosePrice($symbol, $position->position_type);

                if (!$currentPrice || $currentPrice <= 0) {
                    throw new \Exception("Failed to get current price for {$position->symbol}");
                }

                // Calculate PNL
                if ($position->position_type === 'LONG') {
                    $pnl = ($currentPrice - $position->avg_price) * $position->qty;
                } else {
                    $pnl = ($position->avg_price - $currentPrice) * $position->qty;
                }

                $pnlPercentage = ($pnl / $position->investment) * 100;

                $portfolio = $position->portfolio;

                Log::info("🔧 Manual Close - AVAILABLE BALANCE SYSTEM", [
                    'position_id' => $position->id,
                    'old_balance' => $portfolio->balance,
                    'investment' => $position->investment,
                    'pnl' => $pnl,
                    'new_balance' => $portfolio->balance + $pnl
                ]);

                // Update position
                $position->update([
                    'status' => 'CLOSED',
                    'current_price' => $currentPrice,
                    'exit_price' => $currentPrice,
                    'floating_pnl' => $pnl,
                    'realized_pnl' => $pnl,
                    'pnl_percentage' => $pnlPercentage,
                    'closed_at' => now(),
                    'close_reason' => $reason
                ]);

                // ✅ FIXED: Hanya tambahkan PnL ke balance
                $portfolio->addPnl($pnl);

                // Create trade history
                TradeHistory::create([
                    'user_id' => $position->user_id,
                    'ai_decision_id' => $position->ai_decision_id,
                    'position_id' => $position->id,
                    'symbol' => $position->symbol,
                    'action' => 'SELL',
                    'position_type' => $position->position_type,
                    'qty' => $position->qty,
                    'price' => $currentPrice,
                    'amount' => $position->investment + $pnl,
                    'pnl' => $pnl,
                    'pnl_percentage' => $pnlPercentage,
                    'notes' => "MANUAL CLOSE: " . $reason,
                ]);

                // NOTIFIKASI: Trigger untuk manual close
                $this->notifyUserPositionClosed(
                    $position->user_id,
                    $position->symbol,
                    $position->position_type,
                    $pnl,
                    $reason
                );

                return [
                    'success' => true,
                    'pnl' => $pnl,
                    'close_price' => $currentPrice,
                    'available_balance' => $portfolio->available_balance,
                    'message' => "Position closed successfully. PNL: $" . number_format($pnl, 2)
                ];
            });

        } catch (\Exception $e) {
            Log::error("Manual close failed for position {$positionId}: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to close position: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get proper close price berdasarkan position type
     */
    private function getClosePrice($symbol, $positionType)
    {
        if ($positionType === 'LONG') {
            return $this->binanceService->getBidPrice($symbol);
        } else {
            return $this->binanceService->getAskPrice($symbol);
        }
    }

    /**
     * Close all positions for a user - DENGAN NOTIFIKASI
     */
    public function closeAllPositions($userId, $reason = "Emergency Close All")
    {
        $openPositions = UserPosition::where('user_id', $userId)
            ->where('status', 'OPEN')
            ->get();

        $closedCount = 0;
        $totalPnl = 0;
        $results = [];

        foreach ($openPositions as $position) {
            $result = $this->closePositionManually($position->id, $userId, $reason);
            $results[] = [
                'position_id' => $position->id,
                'symbol' => $position->symbol,
                'result' => $result
            ];

            if ($result['success']) {
                $closedCount++;
                $totalPnl += $result['pnl'];
            }
        }

        // NOTIFIKASI: Trigger untuk close all positions
        if ($closedCount > 0) {
            $this->notifyUserTradeExecution(
                $userId,
                'ALL',
                'CLOSE_ALL',
                "All positions ({$closedCount}) have been closed. Total PnL: $" . number_format($totalPnl, 2)
            );
        }

        Log::info("🛑 EMERGENCY CLOSE ALL: User {$userId} closed {$closedCount} positions - Total PNL: \${$totalPnl}");

        return [
            'success' => true,
            'closed_count' => $closedCount,
            'total_pnl' => $totalPnl,
            'results' => $results,
            'message' => "Closed {$closedCount} positions. Total PNL: $" . number_format($totalPnl, 2)
        ];
    }

    /**
     * Logic untuk auto-close position
     */
    private function shouldAutoClosePosition(UserPosition $position, $currentPrice)
    {
        // 1. Manual SL/TP
        if ($position->stop_loss && $position->take_profit) {
            if ($position->position_type === 'LONG') {
                if ($currentPrice <= $position->stop_loss) {
                    return ['close' => true, 'reason' => 'Manual Stop Loss'];
                }
                if ($currentPrice >= $position->take_profit) {
                    return ['close' => true, 'reason' => 'Manual Take Profit'];
                }
            } else { // SHORT
                if ($currentPrice >= $position->stop_loss) {
                    return ['close' => true, 'reason' => 'Manual Stop Loss'];
                }
                if ($currentPrice <= $position->take_profit) {
                    return ['close' => true, 'reason' => 'Manual Take Profit'];
                }
            }
        }

        // 2. Auto SL/TP berdasarkan risk mode
        $holdingHours = $position->opened_at->diffInHours(now());
        
        // Hitung PnL percentage
        if ($position->position_type === 'LONG') {
            $pnlPercentage = (($currentPrice - $position->avg_price) / $position->avg_price) * 100;
        } else {
            $pnlPercentage = (($position->avg_price - $currentPrice) / $position->avg_price) * 100;
        }

        $portfolio = $position->portfolio;
        
        // Dynamic SL/TP berdasarkan risk mode
        $slPercent = 0;
        $tpPercent = 0;

        switch ($portfolio->risk_mode) {
            case 'CONSERVATIVE':
                $slPercent = -2;
                $tpPercent = 4;
                break;
            case 'MODERATE':
                $slPercent = -3;
                $tpPercent = 6;
                break;
            case 'AGGRESSIVE':
                $slPercent = -5;
                $tpPercent = 10;
                break;
        }

        if ($pnlPercentage <= $slPercent) {
            return ['close' => true, 'reason' => "Auto Stop Loss {$slPercent}%"];
        }

        if ($pnlPercentage >= $tpPercent) {
            return ['close' => true, 'reason' => "Auto Take Profit {$tpPercent}%"];
        }

        // 3. Time-based close
        if ($holdingHours >= 24) {
            return ['close' => true, 'reason' => "Time Limit 24h"];
        }

        return ['close' => false, 'reason' => ''];
    }

    /**
     * Calculate dynamic risk amount based on regime, volatility and market conditions
     */
    private function calculateRiskAmount($portfolio, $confidence, $symbol)
    {
        $baseAmount = $portfolio->calculateRiskAmount($confidence);
        
        // ✅ NEW: Apply market risk adjustment jika ada
        $marketAdjustment = 1.0;
        if (isset($this->currentDecision) && isset($this->currentDecision->risk_adjustment)) {
            $marketAdjustment = $this->currentDecision->risk_adjustment;
            Log::info("🎯 Applying market risk adjustment: {$marketAdjustment}");
        }
        
        // Get regime data untuk additional adjustment
        $regime = \App\Models\MarketRegime::where('symbol', $symbol)
            ->orderBy('timestamp', 'desc')
            ->first();
            
        if (!$regime) {
            Log::warning("⚠️ No regime data for {$symbol} - using base risk amount");
            return $baseAmount * $marketAdjustment; // ✅ Apply market adjustment
        }

        $regimeType = $regime->regime;
        $regimeConfidence = $regime->regime_confidence;
        $volatility = $regime->volatility_24h;
        $anomalyScore = $regime->anomaly_score;

        Log::info("🎯 Dynamic Sizing for {$symbol}: {$regimeType} ({$regimeConfidence}%), Vol: " . round($volatility * 100, 2) . "%, Anomaly: " . round($anomalyScore * 100, 1) . "%");

        $adjustment = 1.0; // Base multiplier

        // 1. REGIME-BASED ADJUSTMENT
        $regimeMultiplier = match($regimeType) {
            'bull' => 1.2,      // Increase size in bull markets
            'bear' => 0.8,      // Reduce size in bear markets
            'neutral' => 1.0,   // Normal size
            'reversal' => 0.5,  // Significantly reduce in reversal
            default => 1.0
        };

        // 2. REGIME CONFIDENCE ADJUSTMENT
        $confidenceMultiplier = match(true) {
            $regimeConfidence > 0.8 => 1.1,  // High confidence → slightly increase
            $regimeConfidence > 0.6 => 1.0,  // Medium confidence → normal
            $regimeConfidence > 0.4 => 0.9,  // Low confidence → reduce
            default => 0.8                   // Very low confidence → significant reduce
        };

        // 3. VOLATILITY ADJUSTMENT (Higher volatility = smaller positions)
        $volatilityMultiplier = match(true) {
            $volatility > 0.05 => 0.6,  // Very high volatility (>5%) - reduce 40%
            $volatility > 0.03 => 0.8,  // High volatility (3-5%) - reduce 20%
            $volatility > 0.02 => 0.9,  // Medium-high volatility (2-3%) - reduce 10%
            $volatility > 0.01 => 1.0,  // Normal volatility (1-2%) - normal size
            $volatility > 0.005 => 1.1, // Low volatility (0.5-1%) - slightly increase
            default => 1.2              // Very low volatility (<0.5%) - increase
        };

        // 4. ANOMALY SCORE ADJUSTMENT
        $anomalyMultiplier = match(true) {
            $anomalyScore > 0.7 => 0.0,   // Extreme anomaly - NO TRADING (should be caught by validation)
            $anomalyScore > 0.5 => 0.5,   // High anomaly - reduce 50%
            $anomalyScore > 0.3 => 0.8,   // Moderate anomaly - reduce 20%
            default => 1.0                // Normal - no adjustment
        };

        // 5. CONFIDENCE-BASED ADJUSTMENT (from AI decision)
        $aiConfidenceMultiplier = match(true) {
            $confidence >= 80 => 1.2,    // Very high AI confidence
            $confidence >= 70 => 1.1,    // High AI confidence  
            $confidence >= 60 => 1.0,    // Normal AI confidence
            $confidence >= 50 => 0.9,    // Low AI confidence
            default => 0.8               // Very low AI confidence
        };

        // Calculate final adjustment
        $finalMultiplier = $regimeMultiplier * $confidenceMultiplier * $volatilityMultiplier * $anomalyMultiplier * $aiConfidenceMultiplier;
        
        // Apply bounds (min 10%, max 200% of base amount)
        $finalMultiplier = max(0.1, min(2.0, $finalMultiplier));
        
        // ✅ APPLY MARKET ADJUSTMENT
        $finalMultiplier = $finalMultiplier * $marketAdjustment;
        
        $finalAmount = $baseAmount * $finalMultiplier;

        Log::info("📊 Position Sizing Calculation:", [
            'symbol' => $symbol,
            'base_amount' => $baseAmount,
            'final_amount' => $finalAmount,
            'multiplier' => $finalMultiplier,
            'market_adjustment' => $marketAdjustment,  // ✅ TAMBAH INI
            'breakdown' => [
                'regime' => $regimeMultiplier,
                'confidence' => $confidenceMultiplier, 
                'volatility' => $volatilityMultiplier,
                'anomaly' => $anomalyMultiplier,
                'ai_confidence' => $aiConfidenceMultiplier
            ]
        ]);

        return $finalAmount;
    }
    /**
     * Check dan execute SL/TP secara real-time
     */
    public function executeSLTPMonitoring()
    {
        $openPositions = UserPosition::where('status', 'OPEN')
            ->where(function($query) {
                $query->whereNotNull('stop_loss')
                      ->orWhereNotNull('take_profit');
            })
            ->get();

        $executedCount = 0;

        foreach ($openPositions as $position) {
            try {
                $currentPrice = $this->binanceService->getCurrentPrice(
                    str_replace('USDT', '', $position->symbol)
                );

                if (!$currentPrice || $currentPrice <= 0) continue;

                $shouldClose = false;
                $reason = '';

                if ($position->stop_loss && $position->take_profit) {
                    if ($position->position_type === 'LONG') {
                        if ($currentPrice <= $position->stop_loss) {
                            $shouldClose = true;
                            $reason = 'Manual Stop Loss';
                        } elseif ($currentPrice >= $position->take_profit) {
                            $shouldClose = true;
                            $reason = 'Manual Take Profit';
                        }
                    } else {
                        if ($currentPrice >= $position->stop_loss) {
                            $shouldClose = true;
                            $reason = 'Manual Stop Loss';
                        } elseif ($currentPrice <= $position->take_profit) {
                            $shouldClose = true;
                            $reason = 'Manual Take Profit';
                        }
                    }
                }

                if ($shouldClose) {
                    $this->closePosition($position, $currentPrice, $reason);
                    $executedCount++;
                }

            } catch (\Exception $e) {
                Log::error("SL/TP monitoring failed for position {$position->id}: " . $e->getMessage());
            }
        }

        Log::info("🎯 SL/TP Monitoring: {$executedCount} positions executed");
        return $executedCount;
    }

    /**
     * Repair portfolio data yang corrupt
     */
    public function repairPortfolioData($userId)
    {
        try {
            return DB::transaction(function () use ($userId) {
                $portfolio = UserPortfolio::where('user_id', $userId)->first();
                
                if (!$portfolio) {
                    throw new \Exception("Portfolio not found for user {$userId}");
                }

                // Hitung ulang semua dari awal
                $openPositions = UserPosition::where('user_id', $userId)
                    ->where('status', 'OPEN')
                    ->get();

                $totalFloatingPnl = 0;
                $totalInvestment = 0;

                foreach ($openPositions as $position) {
                    $currentPrice = $this->binanceService->getCurrentPrice(
                        str_replace('USDT', '', $position->symbol)
                    );
                    
                    if ($currentPrice && $currentPrice > 0) {
                        $position->updateFloatingPnl($currentPrice);
                        $totalFloatingPnl += $position->floating_pnl;
                        $totalInvestment += $position->investment;
                    }
                }

                // Hitung realized PnL dari trade history
                $realizedPnl = TradeHistory::where('user_id', $userId)
                    ->whereNotNull('pnl')
                    ->sum('pnl');

                // Balance seharusnya = initial_balance + realized_pnl
                $correctBalance = $portfolio->initial_balance + $realizedPnl;
                $correctEquity = $correctBalance + $totalFloatingPnl;

                // Update portfolio dengan nilai yang benar
                $portfolio->update([
                    'balance' => max(0, $correctBalance),
                    'equity' => max(0, $correctEquity),
                    'realized_pnl' => $realizedPnl,
                    'floating_pnl' => $totalFloatingPnl
                ]);

                Log::info("🔧 Portfolio repaired for user {$userId}", [
                    'old_balance' => $portfolio->getOriginal('balance'),
                    'new_balance' => $correctBalance,
                    'realized_pnl' => $realizedPnl,
                    'floating_pnl' => $totalFloatingPnl,
                    'available_balance' => $portfolio->available_balance
                ]);

                return [
                    'success' => true,
                    'message' => 'Portfolio data repaired successfully'
                ];
            });

        } catch (\Exception $e) {
            Log::error("Portfolio repair failed for user {$userId}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Repair failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Enhanced position closed notification dengan Pusher
     */
    private function notifyUserPositionClosed($userId, $symbol, $positionType, $pnl, $reason)
    {
        try {
            $action = 'CLOSE';
            if (str_contains($reason, 'Stop Loss')) {
                $action = 'STOP_LOSS';
            } elseif (str_contains($reason, 'Take Profit')) {
                $action = 'TAKE_PROFIT';
            }

            $pnlFormatted = number_format($pnl, 2);

            // Existing event
            event(new TradingExecutedEvent(
                $userId,
                "Position Closed - {$symbol}",
                "{$positionType} position closed. PnL: \${$pnlFormatted} - {$reason}",
                strtolower($action),
                [
                    'symbol' => $symbol,
                    'position_type' => $positionType,
                    'pnl' => $pnl,
                    'pnl_formatted' => $pnlFormatted,
                    'reason' => $reason,
                    'type' => 'position_closed',
                    'timestamp' => now()->toDateTimeString()
                ]
            ));

            // ✅ TAMBAHKAN INI: Pusher real-time notification
            $pusher = new \Pusher\Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'useTLS' => true
                ]
            );

            $icon = $pnl >= 0 ? '💰' : '🔴';
            $title = $action === 'STOP_LOSS' ? "🛑 Stop Loss - {$symbol}" : 
                    ($action === 'TAKE_PROFIT' ? "🎯 Take Profit - {$symbol}" : "⚡ Position Closed - {$symbol}");

            $notificationData = [
                'id' => uniqid(),
                'title' => $title,
                'message' => "{$positionType} position closed\nPnL: \${$pnlFormatted}\nReason: {$reason}",
                'type' => strtolower($action),
                'data' => [
                    'symbol' => $symbol,
                    'action' => $action,
                    'position_type' => $positionType,
                    'pnl' => $pnl,
                    'pnl_formatted' => $pnlFormatted,
                    'reason' => $reason,
                    'type' => 'position_closed',
                    'timestamp' => now()->toISOString()
                ],
                'received_at' => now()->toISOString()
            ];

            $pusher->trigger("private-user-{$userId}", 'new.signal', $notificationData);
            Log::info("📢 PUSHER NOTIFICATION: Position closed sent to user {$userId}");

        } catch (\Exception $e) {
            Log::error("❌ Pusher notification failed: " . $e->getMessage());
        }
    }
    /**
     * Validate decision with regime data before execution
     */
    private function validateWithRegime(AiDecision $decision)
    {
        // ✅ NEW: MARKET REGIME SUMMARY VALIDATION (Primary)
        $marketValidation = $this->validateWithMarketRegimeSummary($decision);
        
        if (!$marketValidation['valid']) {
            return $marketValidation;
        }

        // ✅ Fallback ke symbol-specific validation
        return $this->validateWithSymbolRegime($decision);
    }
    /**
     * Enhanced validation using Market Regime Summary
     */
    private function validateWithMarketRegimeSummary(AiDecision $decision)
    {
        // Get latest market summary
        $marketSummary = RegimeSummary::today()->first();
        
        if (!$marketSummary) {
            Log::debug("No market summary available - using symbol-specific regime");
            return $this->validateWithSymbolRegime($decision); // Fallback ke old logic
        }

        $marketRegime = $marketSummary->market_sentiment; // 'bullish', 'bearish', 'neutral'
        $marketHealth = $marketSummary->market_health_score; // 0-100
        $trendStrength = $marketSummary->trend_strength; // 0-100
        $regimePercentages = $marketSummary->regime_percentages; // bull%, bear%, etc.

        Log::info("🏛️  Market Summary: {$marketRegime} (Health: {$marketHealth}, Trend: {$trendStrength}%)");

        return $this->validateWithMarketContext(
            $decision, 
            $marketRegime, 
            $marketHealth, 
            $trendStrength,
            $regimePercentages
        );
    }

    /**
     * Original symbol-specific regime validation (rename dari validateWithRegime)
     */
    private function validateWithSymbolRegime(AiDecision $decision)
    {
        $symbol = $decision->symbol;
        
        // Get latest regime data
        $regime = \App\Models\MarketRegime::where('symbol', $symbol)
            ->orderBy('timestamp', 'desc')
            ->first();
            
        if (!$regime) {
            Log::warning("⚠️ No regime data available for {$symbol} - proceeding with caution");
            return ['valid' => true, 'reason' => 'No regime data available'];
        }
        
        $action = $decision->action;
        $regimeType = $regime->regime;
        $regimeConfidence = $regime->regime_confidence;
        $anomalyScore = $regime->anomaly_score;
        
        Log::info("🔍 Symbol Regime for {$symbol}: {$regimeType} ({$regimeConfidence}%), Anomaly: {$anomalyScore}");

        // 1. ANOMALY SAFETY CHECK
        if ($anomalyScore > 0.7) {
            return [
                'valid' => false,
                'reason' => "High anomaly score detected: " . round($anomalyScore * 100, 1) . "% - Market unstable"
            ];
        }

        // 2. HIGH CONFIDENCE REGIME CONFLICTS
        if ($regimeConfidence > 0.7) {
            // Bull regime conflict with SELL
            if ($regimeType === 'bull' && $action === 'SELL') {
                return [
                    'valid' => false, 
                    'reason' => "High confidence BULL regime (" . round($regimeConfidence * 100, 1) . "%) conflict with SELL decision"
                ];
            }
            
            // Bear regime conflict with BUY
            if ($regimeType === 'bear' && $action === 'BUY') {
                return [
                    'valid' => false,
                    'reason' => "High confidence BEAR regime (" . round($regimeConfidence * 100, 1) . "%) conflict with BUY decision"
                ];
            }
            
            // Reversal regime - avoid trading
            if ($regimeType === 'reversal') {
                return [
                    'valid' => false,
                    'reason' => "REVERSAL regime detected - Avoid trading during market transitions"
                ];
            }
        }

        // 3. VOLATILITY WARNING (log only, don't block)
        if ($regime->volatility_24h > 0.05) { // 5% volatility
            Log::warning("🌪️ High volatility detected for {$symbol}: " . round($regime->volatility_24h * 100, 2) . "%");
        }

        // 4. MODERATE ANOMALY WARNING (reduce position size later)
        if ($anomalyScore > 0.5) {
            Log::warning("🚨 Moderate anomaly for {$symbol}: " . round($anomalyScore * 100, 1) . "% - Consider reducing position size");
        }

        return [
            'valid' => true, 
            'reason' => "Symbol regime passed - {$regimeType} regime, " . round($regimeConfidence * 100, 1) . "% confidence"
        ];
    }

    /**
     * Smart validation dengan market context
     */
    private function validateWithMarketContext($decision, $marketRegime, $marketHealth, $trendStrength, $regimePercentages)
    {
        $action = $decision->action;
        $symbol = $decision->symbol;

        // ✅ MARKET HEALTH CHECK
        if ($marketHealth < 30) {
            return ['valid' => false, 'reason' => "Poor market health: {$marketHealth}"];
        }

        // ✅ TREND STRENGTH ADJUSTMENT
        $riskAdjustment = 1.0; // Default
        if ($trendStrength < 40) {
            // Weak trend - reduce position size
            $riskAdjustment = 0.7;
            Log::info("📉 Weak trend strength: {$trendStrength}% - reducing position size");
        }

        // ✅ MARKET REGIME ALIGNMENT
        $alignmentScore = $this->calculateAlignmentScore($action, $marketRegime, $regimePercentages);
        
        if ($alignmentScore < 0.3) {
            return [
                'valid' => false, 
                'reason' => "Poor alignment with market regime: {$marketRegime}"
            ];
        }

        // ✅ VOLATILITY CHECK
        if ($this->isHighVolatilityMarket($regimePercentages)) {
            Log::warning("🌪️  High volatility market - extra caution for {$symbol}");
            $riskAdjustment = min($riskAdjustment, 0.6);
        }

        // ✅ Store risk adjustment untuk digunakan di calculateRiskAmount
        if (isset($this->currentDecision)) {
            $this->currentDecision->risk_adjustment = $riskAdjustment;
        }

        return [
            'valid' => true, 
            'reason' => "Market validation passed: {$marketRegime} regime",
            'risk_adjustment' => $riskAdjustment
        ];
    }

    /**
     * Calculate alignment score between trade action and market regime
     */
    private function calculateAlignmentScore($action, $marketRegime, $regimePercentages)
    {
        $bullRatio = $regimePercentages['bull'] ?? 0;
        $bearRatio = $regimePercentages['bear'] ?? 0;
        
        // Base alignment
        $alignment = 0.5; // Neutral start
        
        // Action alignment dengan market sentiment
        if ($marketRegime === 'bullish' || $marketRegime === 'extremely_bullish') {
            $alignment = $action === 'BUY' ? 0.8 : 0.3;
        } elseif ($marketRegime === 'bearish' || $marketRegime === 'extremely_bearish') {
            $alignment = $action === 'SELL' ? 0.8 : 0.3;
        } else { // neutral
            $alignment = 0.6; // Slightly favor both in neutral
        }
        
        // Adjust berdasarkan regime distribution
        if ($bullRatio > 60 && $action === 'BUY') $alignment += 0.2;
        if ($bearRatio > 60 && $action === 'SELL') $alignment += 0.2;
        
        return min(1.0, max(0.0, $alignment));
    }

    /**
     * Check if market is high volatility
     */
    private function isHighVolatilityMarket($regimePercentages)
    {
        $volatileRatio = $regimePercentages['volatile'] ?? 0;
        return $volatileRatio > 30; // Jika >30% symbols volatile
    }
    /**
     * Enhanced error notification dengan Pusher
     */
    private function notifyUserTradeError($userId, $symbol, $action, $errorMessage)
    {
        try {
            // Existing event
            event(new TradingExecutedEvent(
                $userId,
                "Trade Error - {$symbol}",
                $errorMessage,
                'error',
                [
                    'symbol' => $symbol,
                    'action' => $action,
                    'type' => 'trade_error',
                    'timestamp' => now()->toDateTimeString()
                ]
            ));

            // ✅ TAMBAHKAN INI: Pusher real-time notification
            $pusher = new \Pusher\Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'useTLS' => true
                ]
            );

            $notificationData = [
                'id' => uniqid(),
                'title' => "❌ Trade Error - {$symbol}",
                'message' => $errorMessage,
                'type' => 'error',
                'data' => [
                    'symbol' => $symbol,
                    'action' => $action,
                    'type' => 'trade_error',
                    'timestamp' => now()->toISOString()
                ],
                'received_at' => now()->toISOString()
            ];

            $pusher->trigger("private-user-{$userId}", 'new.signal', $notificationData);
            Log::error("📢 PUSHER NOTIFICATION: Trade error sent to user {$userId}");

        } catch (\Exception $e) {
            Log::error("❌ Pusher notification failed: " . $e->getMessage());
        }
    }
}

