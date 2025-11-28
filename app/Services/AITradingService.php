<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiDecision;
use App\Models\UserPosition;
use App\Models\MarketRegime;
use App\Models\RegimeSummary;
use App\Jobs\ExecuteTradingDecisionJob;

class AITradingService
{
    private $openaiApiKey;
    private $binanceService;
    private $adaptiveLearningService;

    // Enhanced Configuration
    private $minConfidenceThreshold = 50;
    private $maxDecisionsPerSymbolPerHour = 2;
    private $lossCooldownHours = 3;
    private $volumeSpikeThreshold = 2.0; // 2x average volume
    private $extremeVolumeThreshold = 3.0; // 3x average volume

    public function __construct(BinanceService $binanceService, AdaptiveLearningService $adaptiveLearningService)
    {
        $this->openaiApiKey = env('OPENAI_API_KEY');
        $this->binanceService = $binanceService;
        $this->adaptiveLearningService = $adaptiveLearningService;
        
        if (empty($this->openaiApiKey)) {
            Log::error('❌ OpenAI API Key is empty! Check .env file');
        } else {
            Log::info('✅ OpenAI API Key loaded: ' . substr($this->openaiApiKey, 0, 10) . '...');
        }
    }

    /**
     * Generate trading decision using GPT - ENHANCED WITH VOLUME SPIKE ANALYSIS
     */
    public function generateTradingDecision($symbols = ['BTC', 'ETH'])
    {
        if (empty($this->openaiApiKey) || $this->openaiApiKey === 'sk-your-actual-api-key-here') {
            Log::error('❌ OpenAI API Key not configured properly');
            return null;
        }

        // Get market context with volume analysis
        $marketSummary = RegimeSummary::today()->first();
        $marketAnalysis = $this->binanceService->getMultipleMarketData($symbols);
        
        if (empty($marketAnalysis)) {
            Log::error('Failed to get market data for AI analysis');
            return null;
        }

        // Enhanced logging with volume context
        if ($marketSummary) {
            $volumeSpikeSymbols = $this->getVolumeSpikeSymbols($marketAnalysis);
            Log::info("🏛️  AI Decision - Market: {$marketSummary->market_sentiment}, Health: {$marketSummary->market_health_score}%, Volume Spikes: " . implode(', ', $volumeSpikeSymbols));
        }

        $decisions = [];
        
        // Generate decision untuk setiap symbol dengan volume-aware filtering
        foreach ($symbols as $symbol) {
            if (!isset($marketAnalysis[$symbol])) {
                continue;
            }

            // ✅ ENHANCED: Volume-aware symbol checking
            if (!$this->canTradeSymbolWithVolumeContext($symbol, $marketAnalysis[$symbol])) {
                continue;
            }

            $decision = $this->generateSymbolDecision($symbol, $marketAnalysis[$symbol]);
            if ($decision && $this->isValidDecision($decision)) {
                // ✅ APPLY VOLUME SPIKE CONFIDENCE BOOST
                $decision = $this->applyVolumeSpikeAdjustments($decision, $marketAnalysis);
                $decisions[] = $decision;
            }
        }

        if (empty($decisions)) {
            Log::info("⏭️ No high-confidence decisions generated for any symbols");
            return null;
        }

        // Execute semua decisions yang valid
        $executedDecisions = [];
        foreach ($decisions as $decisionData) {
            $decision = $this->saveAndExecuteDecision($decisionData);
            if ($decision) {
                $executedDecisions[] = $decision;
            }
        }

        Log::info("📊 Total decisions executed: " . count($executedDecisions));
        return count($executedDecisions) === 1 ? $executedDecisions[0] : $executedDecisions;
    }

    /**
     * ✅ ENHANCED: Volume-aware symbol checking
     */
    private function canTradeSymbolWithVolumeContext($symbol, $marketData)
    {
        $symbolWithSuffix = $symbol . 'USDT';
        
        // 1. Cek posisi aktif
        if ($this->hasActiveUserPosition($symbolWithSuffix)) {
            Log::info("⏭️ Skipping {$symbol} - Active user position exists");
            return false;
        }
        
        // 2. Cek recent losses
        if ($this->hasRecentBigLoss($symbolWithSuffix)) {
            Log::info("⏭️ Skipping {$symbol} - Recent big loss within {$this->lossCooldownHours} hours");
            return false;
        }
        
        // 3. ✅ NEW: Volume spike priority check
        $volumeRatio = $marketData['volume_data']['volume_ratio'] ?? 1;
        if ($volumeRatio > $this->extremeVolumeThreshold) {
            Log::info("🚨 PRIORITIZING {$symbol} - Extreme volume spike: " . round($volumeRatio, 1) . "x");
            // Override frequency checks untuk extreme volume
            return true;
        }
        
        // 4. Cek decision frequency
        $this->checkDecisionFrequency($symbolWithSuffix);
        
        Log::info("✅ {$symbol} passed all checks - Volume ratio: " . round($volumeRatio, 1) . "x");
        return true;
    }

    /**
     * Check if user has active position for symbol
     */
    private function hasActiveUserPosition($symbolWithSuffix)
    {
        return UserPosition::where('symbol', $symbolWithSuffix)
            ->where('status', 'OPEN')
            ->exists();
    }

    /**
     * Check for recent big losses (>5%) within cooldown period
     */
    private function hasRecentBigLoss($symbolWithSuffix)
    {
        $recentBigLoss = UserPosition::where('symbol', $symbolWithSuffix)
            ->where('status', 'CLOSED')
            ->where('created_at', '>=', now()->subHours($this->lossCooldownHours))
            ->where('pnl_percentage', '<', -5)
            ->exists();
            
        return $recentBigLoss;
    }

    /**
     * Check decision frequency and log warning if too frequent
     */
    private function checkDecisionFrequency($symbolWithSuffix)
    {
        $recentDecisionsCount = AiDecision::where('symbol', $symbolWithSuffix)
            ->where('created_at', '>=', now()->subHour())
            ->count();
            
        if ($recentDecisionsCount >= $this->maxDecisionsPerSymbolPerHour) {
            Log::warning("⚠️ {$symbolWithSuffix} has {$recentDecisionsCount} decisions in 1 hour - consider reducing frequency");
        }
    }

    /**
     * Generate decision untuk single symbol - ENHANCED WITH VOLUME SPIKE ANALYSIS
     */
    private function generateSymbolDecision($symbol, $marketData)
    {
        $prompt = $this->buildEnhancedSymbolPrompt($symbol, $marketData);
        
        try {
            Log::info("🚀 Sending request to OpenAI API for {$symbol}...");
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4.1-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are a professional AI trading expert specializing in volume spike analysis. Analyze technical data with special attention to volume patterns and provide logical trading decisions. Always respond in English using ONLY the requested JSON format. Do not include any additional text, explanations, or markdown outside the JSON structure."
                    ],
                    [
                        'role' => 'user', 
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 500,
                'response_format' => ['type' => 'json_object']
            ]);

            Log::info("📡 OpenAI API Response for {$symbol} - Status: " . $response->status());
            
            if ($response->successful()) {
                return $this->parseGPTResponse($response->json(), [$symbol => $marketData]);
            } else {
                Log::error("❌ OpenAI API Error for {$symbol} - Status: " . $response->status());
                return null;
            }
            
        } catch (\Exception $e) {
            Log::error("❌ GPT Service Exception for {$symbol}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ ENHANCED: Validate decision dengan volume context
     */
    private function isValidDecision($decision)
    {
        // ✅ UPDATE: Confidence threshold sekarang 50
        if ($decision['action'] !== 'HOLD' && $decision['confidence'] < $this->minConfidenceThreshold) {
            Log::info("⏭️ Skipping {$decision['symbol']} - Low confidence ({$decision['confidence']}% < {$this->minConfidenceThreshold}%)");
            return false;
        }

        // ✅ UPDATE: Lower HOLD threshold juga
        if ($decision['action'] === 'HOLD' && $decision['confidence'] < 40) { // ↓ dari 50
            Log::info("⏭️ Skipping {$decision['symbol']} - Low confidence HOLD ({$decision['confidence']}%)");
            return false;
        }

        // ✅ ENHANCED: MARKET CONTEXT VALIDATION WITH VOLUME AWARENESS
        $marketContext = $this->validateDecisionWithVolumeContext($decision);
        if (!$marketContext['valid']) {
            Log::info("⏭️ Skipping {$decision['symbol']} - Market context: " . $marketContext['reason']);
            return false;
        }

        return true;
    }

    /**
     * ✅ ENHANCED: Validate decision dengan volume context
     */
    private function validateDecisionWithVolumeContext($decision)
    {
        $marketSummary = RegimeSummary::today()->first();
        
        if (!$marketSummary) {
            return ['valid' => true, 'reason' => 'No market summary available'];
        }

        $action = $decision['action'];
        $marketSentiment = $marketSummary->market_sentiment;
        $marketHealth = $marketSummary->market_health_score;

        // Skip jika market health sangat buruk
        if ($marketHealth < 25) {
            return ['valid' => false, 'reason' => "Very poor market health: {$marketHealth}/100"];
        }

        // ✅ UPDATE: Adjust confidence requirements untuk threshold 50
        $adjustedMinConfidence = $this->minConfidenceThreshold;
        
        // Check volume spike
        $symbolKey = str_replace('USDT', '', $decision['symbol']);
        $volumeData = $decision['market_data'][$symbolKey] ?? [];
        $volumeRatio = $volumeData['volume_data']['volume_ratio'] ?? 1;
        $priceChangePercent = $volumeData['price_change_percent_24h'] ?? 0;
        
        $hasVolumeSpike = $volumeRatio > $this->volumeSpikeThreshold;
        $hasBullishVolume = $hasVolumeSpike && $priceChangePercent > 0;

        // ✅ UPDATE: Kurangi penalty karena threshold sudah rendah
        if (($marketSentiment === 'bearish' && $action === 'BUY') ||
            ($marketSentiment === 'bullish' && $action === 'SELL')) {
            
            if ($hasBullishVolume) {
                $adjustedMinConfidence += 3; // ↓ dari 5 (karena threshold 50)
            } else {
                $adjustedMinConfidence += 5; // ↓ dari 10
            }
        }

        // ✅ UPDATE: Boost untuk volume spike
        if (($marketSentiment === 'bullish' && $action === 'BUY' && $hasBullishVolume) ||
            ($marketSentiment === 'bearish' && $action === 'SELL' && $hasVolumeSpike && $priceChangePercent < 0)) {
            $adjustedMinConfidence = max(45, $adjustedMinConfidence - 3); // ↓ dari 60
            Log::info("🎯 Volume-trend alignment - reduced confidence requirement for {$decision['symbol']}");
        }

        // Check jika confidence memenuhi adjusted requirement
        if ($decision['confidence'] < $adjustedMinConfidence) {
            return [
                'valid' => false, 
                'reason' => "Low confidence for market context: {$decision['confidence']}% < {$adjustedMinConfidence}%"
            ];
        }

        return ['valid' => true, 'reason' => "Market context validation passed"];
    }

    /**
     * Save and execute decision
     */
    private function saveAndExecuteDecision($decisionData)
    {
        // Save to database
        $decision = AiDecision::create($decisionData);
        
        Log::info("✅ AI Decision Created: {$decision->action} {$decision->symbol} with {$decision->confidence}% confidence");
        
        // ✅ UPDATE: Threshold sekarang 50
        if ($decision->action !== 'HOLD' && $decision->confidence >= $this->minConfidenceThreshold) {
            ExecuteTradingDecisionJob::dispatch($decision->id);
            Log::info("⚡ Trading execution job dispatched for {$decision->symbol}");
        }
        
        return $decision;
    }
    /**
     * ✅ ENHANCED: Build prompt dengan volume spike analysis
     */
    private function buildEnhancedSymbolPrompt($symbol, $marketData)
    {
        $regimeData = $this->getCurrentRegimeData([$symbol]);
        $symbolRegime = $regimeData[$symbol] ?? null;
        
        $marketSummary = RegimeSummary::today()->first();
        
        $volumeData = $marketData['volume_data'] ?? [
            'current_volume' => $marketData['indicators']['current_volume'] ?? 0,
            'volume_ratio' => 1
        ];
        
        $volumeRatio = $volumeData['volume_ratio'] ?? 1;
        $priceChange24h = $marketData['price_change_24h'] ?? 0;
        $priceChangePercent = $marketData['price_change_percent_24h'] ?? 0;
        
        $isVolumeSpike = $volumeRatio > $this->volumeSpikeThreshold;
        $isExtremeVolume = $volumeRatio > $this->extremeVolumeThreshold;

        $prompt = "🎯 VOLUME-AWARE TRADING ANALYSIS - {$symbol}\n\n";
        
        // GLOBAL MARKET CONTEXT SECTION
        if ($marketSummary) {
            $prompt .= "=== GLOBAL MARKET CONTEXT ===\n";
            $prompt .= "🏛️  Overall Market Sentiment: " . strtoupper($marketSummary->market_sentiment) . "\n";
            $prompt .= "📊 Market Health Score: " . $marketSummary->market_health_score . "/100\n";
            $prompt .= "📈 Trend Strength: " . $marketSummary->trend_strength . "%\n";
            $prompt .= "🎯 Regime Distribution: " . 
                       "Bull " . ($marketSummary->regime_percentages['bull'] ?? 0) . "%, " .
                       "Bear " . ($marketSummary->regime_percentages['bear'] ?? 0) . "%, " .
                       "Neutral " . ($marketSummary->regime_percentages['neutral'] ?? 0) . "%\n";
            $prompt .= "🌪️  Volatility Index: " . $marketSummary->volatility_index . "\n\n";
            
            // VOLUME-AWARE TRADING RECOMMENDATIONS
            $prompt .= "VOLUME-AWARE TRADING STRATEGY:\n";
            
            if ($isExtremeVolume) {
                $prompt .= "🚨 EXTREME VOLUME DETECTED: " . round($volumeRatio, 1) . "x average\n";
                $prompt .= "• Institutional movement detected\n";
                $prompt .= "• High conviction trading opportunity\n";
                $prompt .= "• Follow volume direction with confidence\n";
            } elseif ($isVolumeSpike) {
                $prompt .= "📊 VOLUME SPIKE DETECTED: " . round($volumeRatio, 1) . "x average\n";
                $prompt .= "• Significant trading interest\n";
                $prompt .= "• Validate with price action confirmation\n";
            }
            
            if ($marketSummary->market_sentiment === 'bullish' || $marketSummary->market_sentiment === 'extremely_bullish') {
                $prompt .= "• 📈 Favor BUY positions - Market in uptrend\n";
                $prompt .= "• 🎯 BUY volume spikes with price confirmation\n";
                $prompt .= "• ⚠️ SELL only for strong counter-trend signals\n";
            } elseif ($marketSummary->market_sentiment === 'bearish' || $marketSummary->market_sentiment === 'extremely_bearish') {
                $prompt .= "• 📉 Favor SELL positions - Market in downtrend\n";
                $prompt .= "• 🎯 SELL volume spikes with price confirmation\n";
                $prompt .= "• ⚡ BUY only for strong reversal with volume\n";
            } else {
                $prompt .= "• ⚖️ Neutral market - Volume spikes are key signals\n";
            }
            
            $prompt .= "\n";
        }

        // SYMBOL-SPECIFIC ANALYSIS WITH VOLUME CONTEXT
        $prompt .= "=== {$symbol} VOLUME SPIKE ANALYSIS ===\n";
        $prompt .= "💰 Current Price: $" . number_format($marketData['current_price'], 2) . "\n";
        $prompt .= "📈 24h Change: " . round($priceChangePercent, 2) . "% ($" . number_format($priceChange24h, 2) . ")\n";
        
        // VOLUME ANALYSIS SECTION
        $prompt .= "📊 VOLUME METRICS:\n";
        $prompt .= "• Current Volume: " . number_format($volumeData['current_volume']) . "\n";
        $prompt .= "• Volume Ratio: " . round($volumeRatio, 2) . "x average\n";
        $prompt .= "• Volume Status: ";
        
        if ($isExtremeVolume) {
            $prompt .= "EXTREME SPIKE 🚨\n";
        } elseif ($isVolumeSpike) {
            $prompt .= "SIGNIFICANT SPIKE 📊\n";
        } else {
            $prompt .= "NORMAL 📈\n";
        }
        
        // VOLUME-PRICE CONFIRMATION
        $prompt .= "🎯 VOLUME-PRICE CONFIRMATION:\n";
        if ($isVolumeSpike && $priceChangePercent > 5) {
            $prompt .= "• ✅ STRONG BULLISH: High volume + price surge\n";
            $prompt .= "• 🎯 High confidence BUY opportunity\n";
        } elseif ($isVolumeSpike && $priceChangePercent < -5) {
            $prompt .= "• ✅ STRONG BEARISH: High volume + price drop\n";
            $prompt .= "• 🎯 High confidence SELL opportunity\n";
        } elseif ($isVolumeSpike && abs($priceChangePercent) < 2) {
            $prompt .= "• ⚡ ACCUMULATION/DISTRIBUTION: High volume + flat price\n";
            $prompt .= "• 🔍 Wait for price breakout direction\n";
        } elseif (!$isVolumeSpike && abs($priceChangePercent) > 5) {
            $prompt .= "• ⚠️ LOW VOLUME MOVE: Price change without volume confirmation\n";
            $prompt .= "• 🚫 Lower reliability - be cautious\n";
        } else {
            $prompt .= "• 📈 Normal market activity\n";
        }
        $prompt .= "\n";

        // REGIME ANALYSIS SECTION
        if ($symbolRegime) {
            $prompt .= "🎯 MARKET REGIME ANALYSIS:\n";
            $prompt .= "• Regime: " . strtoupper($symbolRegime['regime']) . "\n";
            $prompt .= "• Confidence: " . round($symbolRegime['regime_confidence'] * 100, 1) . "%\n";
            $prompt .= "• 24h Volatility: " . round($symbolRegime['volatility_24h'] * 100, 2) . "%\n";
            $prompt .= "• RSI-14: " . $symbolRegime['rsi_14'] . " (" . $this->getRSILevel($symbolRegime['rsi_14']) . ")\n";
            
            if ($symbolRegime['predicted_trend']) {
                $trend = $symbolRegime['predicted_trend'] > 0 ? 'BULLISH' : 'BEARISH';
                $prompt .= "• Predicted Trend: " . $trend . " (" . round($symbolRegime['predicted_trend'] * 100, 2) . "%)\n";
            }
            
            $prompt .= "\n";
        }

        // TECHNICAL INDICATORS
        $indicators = $marketData['indicators'];
        $prompt .= "📈 TECHNICAL INDICATORS:\n";
        $prompt .= "• RSI: " . round($indicators['rsi'], 2) . " (" . $this->getRSILevel($indicators['rsi']) . ")\n";
        $prompt .= "• MACD Line: " . round($indicators['macd']['macd_line'], 4) . "\n";
        $prompt .= "• MACD Signal: " . round($indicators['macd']['signal_line'], 4) . "\n";
        $prompt .= "• EMA 20: $" . number_format(end($indicators['ema_20']), 2) . "\n";
        
        $priceVsEMA = $marketData['current_price'] > end($indicators['ema_20']) ? 'ABOVE' : 'BELOW';
        $prompt .= "• Price vs EMA20: " . $priceVsEMA . "\n\n";

        // TRADING STRATEGY GUIDELINES
        $prompt .= "🎯 VOLUME-BASED TRADING STRATEGY:\n";
        
        if ($isExtremeVolume) {
            $prompt .= "🚨 EXTREME VOLUME STRATEGY:\n";
            $prompt .= "• High conviction signals\n";
            $prompt .= "• Follow volume direction aggressively\n";
            $prompt .= "• Minimum confidence: 50%\n"; // ↓ dari 65%
        } elseif ($isVolumeSpike) {
            $prompt .= "📊 VOLUME SPIKE STRATEGY:\n";
            $prompt .= "• Strong directional signals\n";
            $prompt .= "• Validate with price confirmation\n";
            $prompt .= "• Minimum confidence: 50%\n"; // ↓ dari 70%
        } else {
            $prompt .= "📈 NORMAL VOLUME STRATEGY:\n";
            $prompt .= "• Standard technical analysis\n";
            $prompt .= "• Require stronger confirmation\n";
            $prompt .= "• Minimum confidence: 55%\n"; // ↓ dari 75%
        }
        
        // ✅ UPDATE: Confidence adjustment rules
        $prompt .= "\nCONFIDENCE ADJUSTMENTS:\n";
        $prompt .= "• Volume spike + trend alignment = +5-10% confidence\n"; // ↓ dari 10-15%
        $prompt .= "• Extreme volume = +10-15% confidence\n"; // ↓ dari 15-20%
        $prompt .= "• Volume disagreement = -5% confidence\n\n"; // ↓ dari 10%
        
        $prompt .= "RESPONSE REQUIREMENTS:\n";
        $prompt .= "- Confidence: 0-100 based on signal strength AND volume confirmation\n";
        $prompt .= "- Action: BUY/SELL/HOLD only\n";
        $prompt .= "- Symbol: Must include 'USDT' suffix\n";
        $prompt .= "- Explanation: Include volume analysis in rationale\n";
        $prompt .= "- Response MUST be valid JSON only, no other text\n\n";
        
        $prompt .= "REQUIRED JSON FORMAT:\n";
        $prompt .= "{\n";
        $prompt .= "  \"symbol\": \"{$symbol}USDT\",\n";
        $prompt .= "  \"action\": \"BUY|SELL|HOLD\",\n";
        $prompt .= "  \"confidence\": 0-100,\n";
        $prompt .= "  \"explanation\": \"Technical and volume analysis for {$symbol}\"\n";
        $prompt .= "}";

        return $prompt;
    }

    /**
     * ✅ NEW: Apply volume spike confidence adjustments
     */
    private function applyVolumeSpikeAdjustments($decision, $marketAnalysis)
    {
        $symbolKey = str_replace('USDT', '', $decision['symbol']);
        $data = $marketAnalysis[$symbolKey] ?? [];
        
        $volumeRatio = $data['volume_data']['volume_ratio'] ?? 1;
        $priceChange = $data['price_change_24h'] ?? 0;
        $priceChangePercent = $data['price_change_percent_24h'] ?? 0;
        
        $isVolumeSpike = $volumeRatio > $this->volumeSpikeThreshold;
        $isExtremeVolume = $volumeRatio > $this->extremeVolumeThreshold;
        $isBullishVolume = $isVolumeSpike && $priceChangePercent > 3;
        $isBearishVolume = $isVolumeSpike && $priceChangePercent < -3;

        $originalConfidence = $decision['confidence'];
        $adjustment = 0;

        // Volume spike confidence boosts
        if ($isExtremeVolume) {
            if (($decision['action'] === 'BUY' && $isBullishVolume) || 
                ($decision['action'] === 'SELL' && $isBearishVolume)) {
                $adjustment = min(12, 100 - $originalConfidence);
                Log::info("🚀 Extreme volume alignment: +{$adjustment}% confidence for {$decision['symbol']}");
            }
        } elseif ($isVolumeSpike) {
            if (($decision['action'] === 'BUY' && $isBullishVolume) || 
                ($decision['action'] === 'SELL' && $isBearishVolume)) {
                $adjustment = min(8, 100 - $originalConfidence);
                Log::info("📊 Volume spike alignment: +{$adjustment}% confidence for {$decision['symbol']}");
            }
        }

        // Apply adjustment
        if ($adjustment > 0) {
            $decision['confidence'] += $adjustment;
            $decision['volume_adjustment'] = $adjustment;
        }

        return $decision;
    }

    /**
     * ✅ NEW: Get symbols with volume spikes
     */
    private function getVolumeSpikeSymbols($marketAnalysis)
    {
        $spikeSymbols = [];
        
        foreach ($marketAnalysis as $symbol => $data) {
            $volumeRatio = $data['volume_data']['volume_ratio'] ?? 1;
            if ($volumeRatio > $this->volumeSpikeThreshold) {
                $spikeLevel = $volumeRatio > $this->extremeVolumeThreshold ? 'EXTREME' : 'HIGH';
                $spikeSymbols[] = "{$symbol} (" . round($volumeRatio, 1) . "x - {$spikeLevel})";
            }
        }
        
        return $spikeSymbols;
    }

    /**
     * Generate optimized trading decision with volume-aware adaptive learning
     */
    public function generateOptimizedTradingDecision($symbols = ['BTC', 'ETH'])
    {
        $optimization = $this->adaptiveLearningService->getOptimizationRecommendations();
        
        Log::info("🎯 Using optimized parameters:", $optimization['recommendations']);

        // Apply volume-aware adaptive filters
        $filteredSymbols = $this->applyVolumeAwareSymbolFilters($symbols, $optimization);
        
        Log::info("🎯 Volume-optimized symbol selection:", [
            'original_symbols' => $symbols,
            'optimized_symbols' => $filteredSymbols
        ]);

        return $this->generateTradingDecision($filteredSymbols);
    }

    /**
     * ✅ ENHANCED: Apply volume-aware adaptive filters
     */
    private function applyVolumeAwareSymbolFilters($symbols, $optimization)
    {
        $filteredSymbols = [];
        $marketSummary = RegimeSummary::today()->first();
        $marketAnalysis = $this->binanceService->getMultipleMarketData($symbols);
        
        foreach ($symbols as $symbol) {
            $shouldInclude = true;
            $volumeData = $marketAnalysis[$symbol]['volume_data'] ?? [];
            $volumeRatio = $volumeData['volume_ratio'] ?? 1;
            
            $hasVolumeSpike = $volumeRatio > $this->volumeSpikeThreshold;
            $hasExtremeVolume = $volumeRatio > $this->extremeVolumeThreshold;

            // ✅ PRIORITIZE VOLUME SPIKE SYMBOLS
            if ($hasExtremeVolume) {
                Log::info("🚨 URGENT: {$symbol} has extreme volume spike - TOP PRIORITY");
                array_unshift($filteredSymbols, $symbol);
                continue;
            }
            
            if ($hasVolumeSpike) {
                Log::info("📊 PRIORITY: {$symbol} has volume spike - elevated priority");
                array_unshift($filteredSymbols, $symbol);
                continue;
            }

            // Apply symbol preferences dari optimization
            if (isset($optimization['detailed_analysis']['preferred_symbols'][$symbol])) {
                $preference = $optimization['detailed_analysis']['preferred_symbols'][$symbol];
                
                if ($preference['preference_weight'] > 1.2) {
                    Log::info("✅ High preference symbol: {$symbol} (Weight: {$preference['preference_weight']})");
                } elseif ($preference['preference_weight'] < 0.8) {
                    Log::info("⚠️ Low preference symbol: {$symbol} (Weight: {$preference['preference_weight']})");
                    $shouldInclude = false;
                }
            }

            // Market context-based filtering - LESS RESTRICTIVE FOR VOLUME SPIKE
            if ($marketSummary && !$hasVolumeSpike) {
                $marketSentiment = $marketSummary->market_sentiment;
                
                // Hanya filter non-volume-spike symbols di bear market
                if (($marketSentiment === 'bearish' || $marketSentiment === 'extremely_bearish') && 
                    !in_array($symbol, ['BTC', 'ETH', 'BNB'])) {
                    Log::info("⏭️ Skipping {$symbol} in bear market - no volume spike");
                    $shouldInclude = false;
                }
                
                // Di poor market health, lebih selective untuk non-volume symbols
                if ($marketSummary->market_health_score < 40 && !in_array($symbol, ['BTC', 'ETH']) && !$hasVolumeSpike) {
                    Log::info("⏭️ Skipping {$symbol} - poor market health, no volume spike");
                    $shouldInclude = false;
                }
            }

            if ($shouldInclude && !in_array($symbol, $filteredSymbols)) {
                $filteredSymbols[] = $symbol;
            }
        }

        // Jika semua symbols difilter out, gunakan BTC & ETH saja
        if (empty($filteredSymbols)) {
            Log::warning("⚠️ All symbols filtered out, using BTC & ETH only");
            return ['BTC', 'ETH'];
        }

        return $filteredSymbols;
    }

    /**
     * Get current regime data for symbols
     */
    private function getCurrentRegimeData($symbols)
    {
        $regimeData = [];
        
        foreach ($symbols as $symbol) {
            $binanceSymbol = $symbol . 'USDT';
            
            $regime = MarketRegime::where('symbol', $binanceSymbol)
                ->orderBy('timestamp', 'desc')
                ->first();
                
            if ($regime) {
                $regimeData[$symbol] = [
                    'regime' => $regime->regime,
                    'regime_confidence' => $regime->regime_confidence,
                    'volatility_24h' => $regime->volatility_24h,
                    'rsi_14' => $regime->rsi_14,
                    'dominance_score' => $regime->dominance_score,
                    'sentiment_score' => $regime->sentiment_score,
                    'predicted_trend' => $regime->predicted_trend,
                    'anomaly_score' => $regime->anomaly_score,
                    'metadata' => $regime->regime_metadata
                ];
                
                Log::info("📊 Regime data loaded for {$symbol}: {$regime->regime} (" . round($regime->regime_confidence * 100, 1) . "%)");
            } else {
                Log::warning("⚠️ No regime data found for {$binanceSymbol}");
            }
        }
        
        return $regimeData;
    }

    /**
     * Get RSI level description
     */
    private function getRSILevel($rsi)
    {
        if ($rsi === null) return 'UNKNOWN';
        
        if ($rsi >= 85) return 'OVERBOUGHT';
        if ($rsi <= 30) return 'OVERSOLD';
        if ($rsi >= 60) return 'BULLISH';
        if ($rsi <= 40) return 'BEARISH';
        return 'NEUTRAL';
    }

    /**
     * Parse GPT response and validate
     */
    private function parseGPTResponse($response, $marketAnalysis)
    {
        try {
            $content = $response['choices'][0]['message']['content'];
            
            // Clean and extract JSON
            $content = trim($content);
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');
            
            if ($jsonStart === false || $jsonEnd === false) {
                throw new \Exception('No JSON found in response');
            }
            
            $jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
            $decision = json_decode($jsonString, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format: ' . json_last_error_msg());
            }
            
            // Validate required fields
            $required = ['symbol', 'action', 'confidence', 'explanation'];
            foreach ($required as $field) {
                if (!isset($decision[$field])) {
                    throw new \Exception("Missing field: {$field}");
                }
            }
            
            // Convert action to uppercase
            $decision['action'] = strtoupper(trim($decision['action']));
            
            // Validate action
            if (!in_array($decision['action'], ['BUY', 'SELL', 'HOLD'])) {
                throw new \Exception('Invalid action: ' . $decision['action']);
            }
            
            // Validate confidence range
            $decision['confidence'] = min(100, max(0, intval($decision['confidence'])));
            
            // Ensure symbol has USDT suffix
            if (strpos($decision['symbol'], 'USDT') === false) {
                $decision['symbol'] .= 'USDT';
            }
            
            // Get current price for the symbol
            $symbolKey = str_replace('USDT', '', $decision['symbol']);
            $decision['price'] = $marketAnalysis[$symbolKey]['current_price'] ?? 0;
            $decision['market_data'] = $marketAnalysis;
            $decision['decision_time'] = now();
            $decision['executed'] = false;
            
            return $decision;
            
        } catch (\Exception $e) {
            Log::error('❌ GPT Response Parsing Error: ' . $e->getMessage());
            Log::error('📝 Raw Response: ' . $response['choices'][0]['message']['content']);
            return null;
        }
    }

    /**
     * Test GPT connection with simple prompt
     */
    public function testConnection()
    {
        if (empty($this->openaiApiKey) || $this->openaiApiKey === 'sk-your-actual-api-key-here') {
            Log::error('❌ OpenAI API Key not configured in .env');
            return false;
        }

        try {
            Log::info('🧪 Testing OpenAI API connection...');
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4.1-mini',
                'messages' => [
                    [
                        'role' => 'user', 
                        'content' => 'Respond with exactly: OK'
                    ]
                ],
                'max_tokens' => 5,
                'temperature' => 0.1
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '';
                $isOk = trim($content) === 'OK';
                
                if ($isOk) {
                    Log::info('✅ OpenAI API connection successful');
                } else {
                    Log::warning('⚠️ OpenAI API connected but unexpected response: ' . $content);
                }
                
                return $response->successful();
            } else {
                Log::error('❌ OpenAI API test failed - Status: ' . $response->status());
                Log::error('❌ Response: ' . $response->body());
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error('❌ OpenAI Connection Test Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get API key info (for debugging)
     */
    public function getApiKeyInfo()
    {
        if (empty($this->openaiApiKey)) {
            return '❌ API Key is empty';
        }
        
        $keyStart = substr($this->openaiApiKey, 0, 7);
        $keyLength = strlen($this->openaiApiKey);
        
        return "✅ API Key: {$keyStart}... (length: {$keyLength})";
    }
}