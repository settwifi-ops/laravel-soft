<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OpenAIService
{
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');
        
        if (!$this->apiKey) {
            throw new Exception('OPENAI_API_KEY not found in .env file');
       }
    }

    /**
     * Analyze trading signal dengan AI - FIXED VERSION
     */
    /**
     * Analyze trading signal dengan PRE-CALCULATED levels - UPDATE METHOD INI
     */
    public function analyzeTradingSignal($signalData, $candleData)
    {
        // === TAMBAHKIN 2 BARIS INI DI AWAL METHOD ===
        // CALCULATE levels dulu sebelum kirim ke AI
        $calculatedLevels = $this->calculateLevelsFromCandles($candleData, $signalData->current_price);
        Log::info("📊 Pre-calculated Levels - Support: {$calculatedLevels['support']}, Resistance: {$calculatedLevels['resistance']}");

        $prompt = $this->buildTraderPrompt($signalData, $candleData);

        try {
            Log::info("📡 Sending request to OpenAI for {$signalData->symbol}...");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->baseUrl, [
                'model' => 'gpt-4.1-mini',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                if (isset($responseData['choices'][0]['message']['content'])) {
                    $aiResponse = $responseData['choices'][0]['message']['content'];
                    Log::info("✅ OpenAI analysis successful for {$signalData->symbol}");
                    
                    $parsedResponse = $this->parseAIResponse($aiResponse);
                    
                    // === TAMBAHKIN 2 BARIS INI ===
                    // FORCE PAKAI CALCULATED LEVELS (bukan dari AI)
                    $parsedResponse['support'] = $calculatedLevels['support'];
                    $parsedResponse['resistance'] = $calculatedLevels['resistance'];
                    
                    Log::info("🎯 Final Analysis with Calculated Levels:");
                    Log::info("   Support: {$parsedResponse['support']}");
                    Log::info("   Resistance: {$parsedResponse['resistance']}");
                    
                    return $parsedResponse;
                }
            }

            // Jika AI gagal, pakai fallback dengan calculated levels
            Log::error("❌ OpenAI failed, using fallback with calculated levels");
            return $this->generateBetterFallbackAnalysis($signalData, $candleData);

        } catch (Exception $e) {
            Log::error('❌ OpenAI Service Exception: ' . $e->getMessage());
            return $this->generateBetterFallbackAnalysis($signalData, $candleData);
        }
    }

    /**
     * Build prompt untuk AI dengan PRE-CALCULATED levels - UPDATE METHOD INI
     */
    private function buildTraderPrompt($signalData, $candleData)
    {
        $candleCount = count($candleData);
        $latestCandle = end($candleData);
        
        $latestClose = $latestCandle[4] ?? $signalData->current_price;
        $latestHigh = $latestCandle[2] ?? $latestClose * 1.02;
        $latestLow = $latestCandle[3] ?? $latestClose * 0.98;

        // === TAMBAHKIN 4 BARIS INI ===
        // PRE-CALCULATE levels dulu (pakai algorithm improved)
        $calculatedLevels = $this->calculateLevelsFromCandles($candleData, $signalData->current_price);
        
        // Calculate recent price range
        $recentCandles = array_slice($candleData, -10);
        $highs = array_column($recentCandles, 2);
        $lows = array_column($recentCandles, 3);
        
        $recentHigh = max($highs);
        $recentLow = min($lows);

        return "
ACT AS A PROFESSIONAL CRYPTO TRADER. ANALYZE THIS SETUP AND PROVIDE CONCISE, ACTIONABLE ANALYSIS IN THE EXACT FORMAT BELOW:

**SYMBOL:** {$signalData->symbol}
**PRICE:** \${$signalData->current_price}
**SCORE:** {$signalData->enhanced_score}/100
**OI CHANGE:** {$signalData->oi_change}%
**FUNDING RATE:** {$signalData->funding_rate}%
**VOLUME SPIKE:** {$signalData->volume_spike_ratio}x
**MOMENTUM:** {$signalData->momentum_regime} ({$signalData->momentum_phase})

**TECHNICAL LEVELS (CALCULATED):**
- Calculated Support: \${$calculatedLevels['support']}
- Calculated Resistance: \${$calculatedLevels['resistance']}
- Recent High: \${$recentHigh}
- Recent Low: \${$recentLow}
- Current: \${$latestClose}

**REQUIRED OUTPUT FORMAT (STRICTLY FOLLOW THIS):**

Bias: [Bullish/Bearish/Neutral] ([probability NUMBER BETWEEN 1-100]%) — [key reasons: OI direction, funding, volume context].
Market Structure: [clear structure description: accumulation/distribution/breakout/breakdown] with [momentum context], [price action behavior].
Liquidity: [liquidity areas with specific levels, stop clusters, market maker interest].
Key Levels:
Support: [USE CALCULATED LEVEL: {$calculatedLevels['support']}] → [significance based on price action].
Resistance: [USE CALCULATED LEVEL: {$calculatedLevels['resistance']}] → [significance based on price action].
Whales: [accumulating/distributing/neutral] [evidence from volume/OI/funding].
Outlook: [1-2 sentence forward-looking statement with specific conditions and price levels].

**CRITICAL REQUIREMENTS:**
- MUST use the calculated support/resistance levels provided above
- MUST include probability percentage in Bias line
- Be direct and confident
- No disclaimers, just pure analysis
        ";
    }

    /**
     * Parse AI response - UPDATE METHOD INI (HAPUS support/resistance parsing)
     */
    private function parseAIResponse($response)
    {
        $lines = explode("\n", trim($response));
        $result = [];
        $currentSection = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) continue;
            
            // Detect section headers
            if (strpos($line, 'Bias:') === 0) {
                $currentSection = 'bias';
                $result['summary'] = $line;
                
                // Extract probability immediately from Bias line
                preg_match('/(\d+)%/', $line, $matches);
                if (isset($matches[1])) {
                    $result['probability'] = $matches[1] . '%';
                    Log::info("🎯 Extracted probability from Bias line: {$result['probability']}");
                }
            } elseif (strpos($line, 'Market Structure:') === 0) {
                $currentSection = 'market_structure';
                $result['market_structure'] = str_replace('Market Structure: ', '', $line);
            } elseif (strpos($line, 'Liquidity:') === 0) {
                $currentSection = 'liquidity';
                $result['liquidity'] = str_replace('Liquidity: ', '', $line);
            } elseif (strpos($line, 'Whales:') === 0) {
                $currentSection = 'whales';
                $result['whales'] = str_replace('Whales: ', '', $line);
            } elseif (strpos($line, 'Outlook:') === 0) {
                $currentSection = 'outlook';
                $outlook = str_replace('Outlook: ', '', $line);
                if (isset($result['summary'])) {
                    $result['summary'] .= " " . $outlook;
                }
            }
            // === HAPUS/COMMENT parsing untuk Support/Resistance ===
            // Biarkan AI tetap output, tapi kita ignore dan pakai calculated ones
        }

        // Fallback probability extraction
        if (!isset($result['probability']) && isset($result['summary'])) {
            preg_match('/(\d+)%/', $result['summary'], $matches);
            $result['probability'] = isset($matches[1]) ? $matches[1] . '%' : '50%';
        }

        // NOTE: Support/Resistance akan di-set dari calculated levels di method analyzeTradingSignal
        // Kita return tanpa support/resistance disini

        $mappedResult = [
            'summary' => $result['summary'] ?? 'Analysis in progress',
            'probability' => $result['probability'] ?? '50% neutral',
            'liquidity' => $result['liquidity'] ?? 'Standard market conditions',
            'market_structure' => $result['market_structure'] ?? 'Developing structure',
            'trend_power' => $this->extractTrendPower($result['market_structure'] ?? ''),
            'momentum' => $this->extractMomentum($result['market_structure'] ?? ''),
            'funding_direction' => $this->extractFundingDirection($result['summary'] ?? ''),
            'whales' => $result['whales'] ?? 'Monitoring activity'
            // Support/Resistance akan di-add nanti di analyzeTradingSignal
        ];

        Log::info("✅ Successfully parsed AI response (without S/R)");
        return $mappedResult;
    }

    /**
     * Extract trend power dari market structure description
     */
    private function extractTrendPower($marketStructure)
    {
        if (strpos(strtolower($marketStructure), 'strong') !== false) {
            return 'strong';
        } elseif (strpos(strtolower($marketStructure), 'weak') !== false) {
            return 'weak';
        } else {
            return 'moderate';
        }
    }

    /**
     * Extract momentum dari market structure description
     */
    private function extractMomentum($marketStructure)
    {
        if (strpos(strtolower($marketStructure), 'bullish') !== false || 
            strpos(strtolower($marketStructure), 'accumulation') !== false) {
            return 'bullish';
        } elseif (strpos(strtolower($marketStructure), 'bearish') !== false || 
                  strpos(strtolower($marketStructure), 'distribution') !== false) {
            return 'bearish';
        } else {
            return 'neutral';
        }
    }

    /**
     * Extract funding direction dari summary
     */
    private function extractFundingDirection($summary)
    {
        if (strpos(strtolower($summary), 'funding positive') !== false || 
            strpos(strtolower($summary), 'long biased') !== false) {
            return 'long biased';
        } elseif (strpos(strtolower($summary), 'funding negative') !== false || 
                  strpos(strtolower($summary), 'short biased') !== false) {
            return 'short biased';
        } else {
            return 'neutral';
        }
    }

    /**
     * Generate better fallback analysis dengan new format
     */
    private function generateBetterFallbackAnalysis($signalData, $candleData)
    {
        Log::info("🔄 Generating fallback analysis for {$signalData->symbol}");
        
        // Calculate levels from candle data
        $levels = $this->calculateLevelsFromCandles($candleData, $signalData->current_price);
        
        $trend = $signalData->trend_strength > 70 ? 'bullish' : ($signalData->trend_strength < 30 ? 'bearish' : 'neutral');
        $probability = $signalData->smart_confidence;
        $fundingBias = ($signalData->funding_rate ?? 0) > 0.01 ? 'long biased' : (($signalData->funding_rate ?? 0) < -0.01 ? 'short biased' : 'neutral');
        $whaleActivity = $signalData->volume_spike_ratio > 2 ? 'accumulating' : ($signalData->volume_spike_ratio < 0.5 ? 'distributing' : 'neutral');
        
        return [
            'summary' => "Bias: {$trend} ({$probability}%) — Monitoring price action for confirmation. Market structure developing.",
            'probability' => "{$probability}% {$trend}",
            'support' => $levels['support'],
            'resistance' => $levels['resistance'],
            'liquidity' => 'Liquidity forming around key levels',
            'market_structure' => 'Market structure in development phase',
            'trend_power' => ($signalData->trend_strength > 80 ? 'strong' : 'moderate') . ' ' . $trend,
            'momentum' => $signalData->momentum_phase ?? 'neutral',
            'funding_direction' => $fundingBias,
            'whales' => $whaleActivity
        ];
    }

    /**
     * Calculate IMPROVED support/resistance dari candle data - METHOD INI SUDAH ADA, TINGGI UPDATE LOGIC
     */
    private function calculateLevelsFromCandles($candleData, $currentPrice)
    {
        if (empty($candleData) || count($candleData) < 10) {
            return [
                'support' => number_format($currentPrice * 0.95, 6),
                'resistance' => number_format($currentPrice * 1.05, 6)
            ];
        }

        Log::info("🔍 Calculating IMPROVED S/R levels from " . count($candleData) . " candles");

        // Convert to structured data
        $candles = [];
        foreach ($candleData as $index => $candle) {
            $candles[] = [
                'index' => $index,
                'high' => (float)$candle[2],
                'low' => (float)$candle[3],
                'close' => (float)$candle[4],
                'volume' => (float)$candle[5],
            ];
        }

        // Find swing highs and lows (more accurate than simple min/max)
        $swingHighs = $this->findSwingHighs($candles, 2);
        $swingLows = $this->findSwingLows($candles, 2);
        
        Log::info("📊 Found " . count($swingHighs) . " swing highs, " . count($swingLows) . " swing lows");

        // Get significant levels
        $resistanceLevels = $this->findSignificantLevels($swingHighs, 'resistance');
        $supportLevels = $this->findSignificantLevels($swingLows, 'support');

        // Get current relevant levels
        $currentSupport = $this->getCurrentSupport($supportLevels, $currentPrice);
        $currentResistance = $this->getCurrentResistance($resistanceLevels, $currentPrice);

        Log::info("🎯 Final Levels - Support: {$currentSupport}, Resistance: {$currentResistance}");

        return [
            'support' => $currentSupport,
            'resistance' => $currentResistance
        ];
    }

    /**
     * Find swing highs (peaks) dengan window - METHOD BARU
     */
    private function findSwingHighs($candles, $window = 2)
    {
        $swingHighs = [];
        $count = count($candles);
        
        for ($i = $window; $i < $count - $window; $i++) {
            $currentHigh = $candles[$i]['high'];
            $isSwingHigh = true;
            
            // Check if current high is higher than neighbors within window
            for ($j = 1; $j <= $window; $j++) {
                if ($candles[$i - $j]['high'] >= $currentHigh || 
                    $candles[$i + $j]['high'] >= $currentHigh) {
                    $isSwingHigh = false;
                    break;
                }
            }
            
            if ($isSwingHigh) {
                $swingHighs[] = $currentHigh;
            }
        }
        
        return $swingHighs;
    }

    /**
     * Find swing lows (troughs) dengan window - METHOD BARU
     */
    private function findSwingLows($candles, $window = 2)
    {
        $swingLows = [];
        $count = count($candles);
        
        for ($i = $window; $i < $count - $window; $i++) {
            $currentLow = $candles[$i]['low'];
            $isSwingLow = true;
            
            // Check if current low is lower than neighbors within window
            for ($j = 1; $j <= $window; $j++) {
                if ($candles[$i - $j]['low'] <= $currentLow || 
                    $candles[$i + $j]['low'] <= $currentLow) {
                    $isSwingLow = false;
                    break;
                }
            }
            
            if ($isSwingLow) {
                $swingLows[] = $currentLow;
            }
        }
        
        return $swingLows;
    }

    /**
     * Find significant levels by clustering nearby prices - METHOD BARU
     */
    private function findSignificantLevels($levels, $type = 'support')
    {
        if (empty($levels)) {
            return [];
        }

        // Group nearby levels (within 0.5% tolerance)
        $clusters = [];
        $tolerance = 0.005; // 0.5%
        
        foreach ($levels as $price) {
            $foundCluster = false;
            
            foreach ($clusters as &$cluster) {
                $clusterPrice = $cluster['price'];
                $priceDiff = abs($price - $clusterPrice) / $clusterPrice;
                
                if ($priceDiff <= $tolerance) {
                    // Add to existing cluster
                    $cluster['count']++;
                    $cluster['price'] = ($cluster['price'] + $price) / 2; // Moving average
                    $foundCluster = true;
                    break;
                }
            }
            
            if (!$foundCluster) {
                // Create new cluster
                $clusters[] = [
                    'price' => $price,
                    'count' => 1,
                    'type' => $type
                ];
            }
        }

        // Sort by strength (number of touches)
        usort($clusters, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        return $clusters;
    }

    /**
     * Get current relevant support level - METHOD BARU
     */
    private function getCurrentSupport($supportLevels, $currentPrice)
    {
        if (empty($supportLevels)) {
            return number_format($currentPrice * 0.98, 6);
        }

        // Find closest support BELOW current price
        $validSupports = array_filter($supportLevels, function($level) use ($currentPrice) {
            return $level['price'] < $currentPrice;
        });

        if (empty($validSupports)) {
            // Use strongest support level
            $strongest = $supportLevels[0];
            return number_format($strongest['price'], 6);
        }

        // Get closest support below current price
        $closest = null;
        $minDistance = PHP_FLOAT_MAX;
        
        foreach ($validSupports as $support) {
            $distance = $currentPrice - $support['price'];
            if ($distance < $minDistance && $distance > 0) {
                $minDistance = $distance;
                $closest = $support;
            }
        }

        return $closest ? number_format($closest['price'], 6) : number_format($currentPrice * 0.98, 6);
    }

    /**
     * Get current relevant resistance level - METHOD BARU
     */
    private function getCurrentResistance($resistanceLevels, $currentPrice)
    {
        if (empty($resistanceLevels)) {
            return number_format($currentPrice * 1.02, 6);
        }

        // Find closest resistance ABOVE current price
        $validResistances = array_filter($resistanceLevels, function($level) use ($currentPrice) {
            return $level['price'] > $currentPrice;
        });

        if (empty($validResistances)) {
            // Use strongest resistance level
            $strongest = $resistanceLevels[0];
            return number_format($strongest['price'], 6);
        }

        // Get closest resistance above current price
        $closest = null;
        $minDistance = PHP_FLOAT_MAX;
        
        foreach ($validResistances as $resistance) {
            $distance = $resistance['price'] - $currentPrice;
            if ($distance < $minDistance && $distance > 0) {
                $minDistance = $distance;
                $closest = $resistance;
            }
        }

        return $closest ? number_format($closest['price'], 6) : number_format($currentPrice * 1.02, 6);
    }

    /**
     * Test OpenAI connection
     */
    public function testConnection()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->baseUrl, [
                'model' => 'gpt-4.1',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Reply with just "OK"'
                    ]
                ],
                'max_tokens' => 5,
                'temperature' => 0.1
            ]);

            if ($response->successful()) {
                Log::info('✅ OpenAI connection test: SUCCESS');
                return true;
            } else {
                Log::error('❌ OpenAI connection test failed: ' . $response->status());
                return false;
            }
            
        } catch (Exception $e) {
            Log::error('❌ OpenAI connection test exception: ' . $e->getMessage());
            return false;
        }
    }
}