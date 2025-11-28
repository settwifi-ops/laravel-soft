<?php

namespace App\Services;

use App\Models\Signal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class TradingAnalysisService
{
    private $binanceService;
    private $openAIService;

    public function __construct(BinanceService $binanceService, OpenAIService $openAIService)
    {
        $this->binanceService = $binanceService;  // ✅ BENAR - Dependency Injection
        $this->openAIService = $openAIService;    // ✅ BENAR - Dependency Injection
    }

    /**
     * Process single signal untuk AI analysis
     */
    public function processSignal($signalId, $force = false)
    {
        $signal = Signal::find($signalId);
        
        if (!$signal) {
            Log::error("Signal {$signalId} not found");
            return false;
        }

        // Check jika perlu update
        if (!$force && !$this->needsUpdate($signal)) {
            Log::info("Signal {$signal->symbol} no update needed");
            return 'skipped';
        }

        try {
            Log::info("🔄 Processing signal: {$signal->symbol} (ID: {$signal->id})");

            // Get candle data dari Binance
            $candleData = $this->binanceService->getCandleData($signal->symbol, '1h', 100);
            
            if (!$candleData || empty($candleData)) {
                Log::error("Failed to get candle data for {$signal->symbol}");
                return false;
            }

            Log::info("📊 Got " . count($candleData) . " candles for {$signal->symbol}");

            // Get AI Analysis
            $analysis = $this->openAIService->analyzeTradingSignal($signal, $candleData);

            // Update signal dengan analysis results
            $success = $this->updateSignalWithAnalysis($signal, $analysis);

            if ($success) {
                Log::info("✅ Successfully analyzed {$signal->symbol}");
                return true;
            } else {
                Log::error("❌ Failed to update {$signal->symbol} in database");
                return false;
            }

        } catch (Exception $e) {
            Log::error("Error processing signal {$signalId}: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Check jika signal perlu di-update - FIXED LOGIC
     */
    private function needsUpdate($signal)
    {
        // Debug logging
        Log::info("🔍 Checking if {$signal->symbol} needs update:");
        Log::info("   Appearance Count: {$signal->appearance_count}");
        Log::info("   Last Summary Count: " . ($signal->last_summary_count ?? 'NULL'));
        Log::info("   AI Summary: " . (!empty($signal->ai_summary) ? 'Exists' : 'NULL/Empty'));

        // 1. Jika last_summary_count null atau 0, berarti belum pernah dianalisa
        if (is_null($signal->last_summary_count) || $signal->last_summary_count == 0) {
            Log::info("   ✅ NEEDS UPDATE: Never analyzed before");
            return true;
        }

        // 2. Jika appearance_count bertambah, perlu update
        if ($signal->appearance_count > $signal->last_summary_count) {
            Log::info("   ✅ NEEDS UPDATE: Appearance count increased");
            return true;
        }

        // 3. Juga allow re-analysis jika AI fields masih kosong atau invalid
        $aiFields = ['ai_summary', 'support_level', 'resistance_level'];
        foreach ($aiFields as $field) {
            $fieldValue = $signal->$field;
            if (empty($fieldValue) || $fieldValue === 'N/A' || strpos($fieldValue, 'Error') !== false) {
                Log::info("   ✅ NEEDS UPDATE: Field {$field} is empty/invalid");
                return true;
            }
        }

        Log::info("   ❌ NO UPDATE NEEDED: All conditions met");
        return false;
    }

    /**
     * Update signal dengan analysis results - FIXED DATA CONVERSION
     */
    public function updateSignalWithAnalysis($signal, $analysis)
    {
        try {
            Log::info("💾 Starting database update for {$signal->symbol}");
            
            // Clean and convert data
            $updateData = [
                'ai_summary' => $this->cleanString($analysis['summary'] ?? null),
                'ai_probability' => $this->extractProbability($analysis['probability'] ?? ''),
                'support_level' => $this->convertToDouble($analysis['support'] ?? null),
                'resistance_level' => $this->convertToDouble($analysis['resistance'] ?? null),
                'liquidity_position' => $this->cleanString($analysis['liquidity'] ?? null),
                'market_structure' => $this->cleanString($analysis['market_structure'] ?? null),
                'trend_power' => $this->cleanString($analysis['trend_power'] ?? null),
                'momentum_category' => $this->cleanString($analysis['momentum'] ?? null),
                'funding_direction' => $this->cleanString($analysis['funding_direction'] ?? null),
                'whale_behavior' => $this->cleanString($analysis['whales'] ?? null),
                'last_summary_count' => $signal->appearance_count,
                'updated_at' => now()
            ];

            Log::info("📦 Cleaned update data:");
            foreach ($updateData as $key => $value) {
                Log::info("   {$key}: " . (is_string($value) ? "'{$value}'" : $value));
            }

            // Filter out null values
            $updateData = array_filter($updateData, function($value) {
                return !is_null($value) && $value !== '';
            });

            Log::info("🔄 Attempting database update...");
            
            // Gunakan DB transaction untuk safety
            DB::beginTransaction();
            
            try {
                $result = $signal->update($updateData);
                
                if ($result) {
                    DB::commit();
                    Log::info("✅ Database updated successfully for {$signal->symbol}");
                    
                    // Verify the update
                    $updatedSignal = Signal::find($signal->id);
                    Log::info("🔍 Verification:");
                    Log::info("   AI Summary: " . ($updatedSignal->ai_summary ?? 'NULL'));
                    Log::info("   Probability: " . ($updatedSignal->ai_probability ?? 'NULL'));
                    Log::info("   Support: " . ($updatedSignal->support_level ?? 'NULL'));
                    Log::info("   Resistance: " . ($updatedSignal->resistance_level ?? 'NULL'));
                    
                    return true;
                } else {
                    DB::rollBack();
                    Log::error("❌ Database update failed for {$signal->symbol}");
                    
                    // Check last error
                    $errorInfo = DB::getPdo()->errorInfo();
                    Log::error("📝 Database error: " . json_encode($errorInfo));
                    
                    return false;
                }
            } catch (Exception $e) {
                DB::rollBack();
                Log::error("❌ Database transaction failed: " . $e->getMessage());
                return false;
            }

        } catch (Exception $e) {
            Log::error("❌ Error updating signal {$signal->id}: " . $e->getMessage());
            Log::error("📝 Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Convert string to double - remove currency symbols and commas
     */
    private function convertToDouble($value)
    {
        if (empty($value)) {
            return null;
        }

        // Remove currency symbols, commas, and trim
        $cleaned = trim(str_replace(['$', ',', ' '], '', $value));
        
        // Convert to double
        $result = (double) $cleaned;
        
        Log::info("💰 Converted '{$value}' to double: {$result}");
        return $result;
    }

    /**
     * Clean string - remove unwanted characters and trim
     */
    private function cleanString($value)
    {
        if (empty($value)) {
            return null;
        }

        $cleaned = trim($value);
        
        // Remove any "Error" strings
        if (strpos($cleaned, 'Error calling GPT') !== false) {
            return null;
        }
        
        return $cleaned;
    }

    /**
     * Extract numeric probability dari string
     */
    private function extractProbability($probabilityText)
    {
        preg_match('/(\d+)%/', $probabilityText, $matches);
        $probability = isset($matches[1]) ? (int)$matches[1] : 50;
        
        Log::info("🎯 Extracted probability from '{$probabilityText}': {$probability}");
        return $probability;
    }

    /**
     * Process multiple signals dalam batch
     */
    public function processSignalBatch($signalIds)
    {
        $results = [
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => []
        ];

        foreach ($signalIds as $signalId) {
            try {
                $result = $this->processSignal($signalId);
                
                if ($result === true) {
                    $results['processed']++;
                    $results['details'][$signalId] = 'success';
                } elseif ($result === 'skipped') {
                    $results['skipped']++;
                    $results['details'][$signalId] = 'skipped';
                } else {
                    $results['failed']++;
                    $results['details'][$signalId] = 'failed';
                }

                // Rate limiting: tunggu 20 detik antara processing
                if (next($signalIds) !== false) {
                    sleep(20);
                }

            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][$signalId] = 'error: ' . $e->getMessage();
                Log::error("Batch processing error for signal {$signalId}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get signals yang perlu di-update - FIXED QUERY
     */
    public function getSignalsNeedingUpdate($limit = 50)
    {
        $query = Signal::where(function($query) {
            $query->whereNull('last_summary_count')
                  ->orWhere('last_summary_count', 0)
                  ->orWhereRaw('appearance_count > last_summary_count')
                  ->orWhere(function($q) {
                      $q->whereNull('ai_summary')
                        ->orWhere('ai_summary', '')
                        ->orWhere('ai_summary', 'like', '%Error%');
                  });
        });

        // Log the query for debugging
        $sql = $query->toSql();
        Log::info("📋 Signals Needing Update Query: {$sql}");

        $signals = $query->orderBy('enhanced_score', 'desc')
                         ->limit($limit)
                         ->get();

        Log::info("📊 Found {$signals->count()} signals needing update");
        
        foreach ($signals as $signal) {
            Log::info("   - {$signal->symbol}: Appearance={$signal->appearance_count}, LastSummary={$signal->last_summary_count}");
        }

        return $signals;
    }

    /**
     * Get all signals untuk batch processing (ignore conditions)
     */
    public function getAllSignals($limit = 50)
    {
        return Signal::orderBy('enhanced_score', 'desc')
                     ->limit($limit)
                     ->get();
    }

    /**
     * Force process signal tanpa checking conditions
     */
    public function forceProcessSignal($signalId)
    {
        return $this->processSignal($signalId, true);
    }

    /**
     * Get signals statistics
     */
    public function getAnalysisStatistics()
    {
        $total = Signal::count();
        $analyzed = Signal::whereNotNull('ai_summary')->count();
        $needsUpdate = $this->getSignalsNeedingUpdate(1000)->count(); // Large limit to count all
        
        return [
            'total_signals' => $total,
            'analyzed_signals' => $analyzed,
            'needs_update' => $needsUpdate,
            'coverage_percentage' => $total > 0 ? round(($analyzed / $total) * 100, 2) : 0
        ];
    }
}