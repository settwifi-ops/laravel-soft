<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BinanceService
{
    private $baseUrl = 'https://fapi.binance.com/fapi/v1';
    
    /**
     * Test connection to Binance API - Improved version
     */
    public function testConnection()
    {
        try {
            $this->info("Testing connection to: {$this->baseUrl}/ping");
            
            $response = Http::timeout(10)
                ->retry(3, 1000) // Retry 3x dengan delay 1 detik
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ])
                ->get($this->baseUrl . '/ping');
                
            if ($response->successful()) {
                $this->info("✅ Binance connection successful");
                return true;
            } else {
                $this->error("❌ Binance connection failed - Status: " . $response->status());
                $this->error("Response: " . $response->body());
                return false;
            }
            
        } catch (Exception $e) {
            $this->error('❌ Binance Connection Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current price for a symbol dengan improved error handling
     */
    public function getCurrentPrice($symbol)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            $this->info("Fetching price for: {$binanceSymbol}");
            
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ])
                ->get($this->baseUrl . '/ticker/price', [
                    'symbol' => $binanceSymbol
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $price = floatval($data['price']);
                $this->info("✅ Price fetched: {$binanceSymbol} = \${$price}");
                return $price;
            } else {
                $this->error("❌ Price fetch failed for {$binanceSymbol}: " . $response->status());
                $this->error("Response: " . $response->body());
                return null;
            }
            
        } catch (Exception $e) {
            $this->error('❌ Price fetch exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get BID price (harga jual) untuk close LONG positions
     */
    public function getBidPrice($symbol)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            $this->info("Fetching BID price for: {$binanceSymbol}");
            
            $orderBook = $this->getOrderBook($binanceSymbol, 5);
            
            if ($orderBook && !empty($orderBook['bids'])) {
                $bidPrice = floatval($orderBook['bids'][0][0]); // Highest bid price
                $this->info("✅ BID price fetched: {$binanceSymbol} = \${$bidPrice}");
                return $bidPrice;
            }
            
            // Fallback ke current price jika gagal
            $this->info("🔄 Fallback to current price for BID");
            return $this->getCurrentPrice($symbol);
            
        } catch (Exception $e) {
            $this->error('❌ BID price exception: ' . $e->getMessage());
            return $this->getCurrentPrice($symbol); // Fallback
        }
    }

    /**
     * Get ASK price (harga beli) untuk close SHORT positions
     */
    public function getAskPrice($symbol)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            $this->info("Fetching ASK price for: {$binanceSymbol}");
            
            $orderBook = $this->getOrderBook($binanceSymbol, 5);
            
            if ($orderBook && !empty($orderBook['asks'])) {
                $askPrice = floatval($orderBook['asks'][0][0]); // Lowest ask price
                $this->info("✅ ASK price fetched: {$binanceSymbol} = \${$askPrice}");
                return $askPrice;
            }
            
            // Fallback ke current price jika gagal
            $this->info("🔄 Fallback to current price for ASK");
            return $this->getCurrentPrice($symbol);
            
        } catch (Exception $e) {
            $this->error('❌ ASK price exception: ' . $e->getMessage());
            return $this->getCurrentPrice($symbol); // Fallback
        }
    }

    /**
     * Get order book data
     */
    public function getOrderBook($symbol, $limit = 5)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ])
                ->get($this->baseUrl . '/depth', [
                    'symbol' => $binanceSymbol,
                    'limit' => $limit
                ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                $this->error("❌ Order book fetch failed: " . $response->status());
                return null;
            }
            
        } catch (Exception $e) {
            $this->error('❌ Order book exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get 24hr ticker dengan bid/ask prices
     */
    public function get24hrTicker($symbol)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get($this->baseUrl . '/ticker/24hr', [
                    'symbol' => $binanceSymbol
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                $tickerInfo = [
                    'symbol' => $data['symbol'],
                    'price' => floatval($data['lastPrice']),
                    'bid' => floatval($data['bidPrice']),
                    'ask' => floatval($data['askPrice']),
                    'high' => floatval($data['highPrice']),
                    'low' => floatval($data['lowPrice']),
                    'volume' => floatval($data['volume']),
                    'price_change' => floatval($data['priceChange']),
                    'price_change_percent' => floatval($data['priceChangePercent'])
                ];
                
                $this->info("✅ 24hr Ticker: {$tickerInfo['symbol']} - Bid: \${$tickerInfo['bid']}, Ask: \${$tickerInfo['ask']}");
                return $tickerInfo;
                
            } else {
                $this->error("❌ 24hr ticker failed: " . $response->status());
                return null;
            }
            
        } catch (Exception $e) {
            $this->error('❌ 24hr ticker exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get best bid/ask prices
     */
    public function getBestBidAsk($symbol)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            
            $response = Http::timeout(10)
                ->retry(2, 500)
                ->get($this->baseUrl . '/ticker/bookTicker', [
                    'symbol' => $binanceSymbol
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                $result = [
                    'symbol' => $data['symbol'],
                    'bid_price' => floatval($data['bidPrice']),
                    'bid_qty' => floatval($data['bidQty']),
                    'ask_price' => floatval($data['askPrice']),
                    'ask_qty' => floatval($data['askQty'])
                ];
                
                $this->info("✅ Best Bid/Ask: {$result['symbol']} - Bid: \${$result['bid_price']}, Ask: \${$result['ask_price']}");
                return $result;
                
            } else {
                $this->error("❌ Best bid/ask failed: " . $response->status());
                return null;
            }
            
        } catch (Exception $e) {
            $this->error('❌ Best bid/ask exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get candle data (OHLC) dari Binance dengan improved handling
     */
    public function getCandleData($symbol, $interval = '1h', $limit = 150)
    {
        try {
            $binanceSymbol = $this->formatSymbol($symbol);
            $this->info("Fetching candle data for: {$binanceSymbol}, interval: {$interval}");
            
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ])
                ->get($this->baseUrl . '/klines', [
                    'symbol' => $binanceSymbol,
                    'interval' => $interval,
                    'limit' => min($limit, 1000)
                ]);

            if ($response->successful()) {
                $candles = $response->json();
                if (count($candles) > 0) {
                    $this->info("✅ Candle data fetched: {$binanceSymbol}, count: " . count($candles));
                    return $candles;
                } else {
                    $this->error("❌ No candle data returned for {$binanceSymbol}");
                    return null;
                }
            } else {
                $this->error("❌ Candle data fetch failed: " . $response->status());
                $this->error("Response: " . $response->body());
                return null;
            }
            
        } catch (Exception $e) {
            $this->error('❌ Candle data exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format symbol untuk Binance API
     */
    public function formatSymbol($symbol)
    {
        $symbol = strtoupper(trim($symbol));
        
        // Jika sudah ada USDT, return as is
        if (str_ends_with($symbol, 'USDT')) {
            return $symbol;
        }
        
        // Tambahkan USDT
        return $symbol . 'USDT';
    }

    /**
     * Get multiple indicators untuk AI analysis
     */
    public function getMarketSummary($symbol, $interval = '1h', $limit = 100)
    {
        $this->info("Getting market summary for: {$symbol}");
        
        $candles = $this->getCandleData($symbol, $interval, $limit);
        $currentPrice = $this->getCurrentPrice($symbol);
        
        if (!$candles || !$currentPrice) {
            $this->error("❌ Failed to get market data for {$symbol}");
            return null;
        }

        $indicators = $this->calculateIndicators($candles);
        $volumeData = $this->getVolumeAnalysis($candles);
        
        $summary = [
            'symbol' => $this->formatSymbol($symbol),
            'current_price' => $currentPrice,
            'candles' => $candles,
            'indicators' => $indicators,
            'volume_data' => $volumeData,
            'timestamp' => now()
        ];

        $this->info("✅ Market summary generated for {$symbol}");
        $this->info("   RSI: " . round($indicators['rsi'], 2));
        $this->info("   MACD: " . round($indicators['macd']['macd_line'], 4));
        $this->info("   Volume Ratio: " . round($volumeData['volume_ratio'], 2));
        
        return $summary;
    }

    /**
     * Calculate technical indicators dari candle data
     */
    private function calculateIndicators($candles)
    {
        if (count($candles) < 20) {
            $this->error("❌ Insufficient candle data for indicators");
            return [
                'rsi' => 50,
                'macd' => ['macd_line' => 0, 'signal_line' => 0, 'histogram' => 0],
                'ema_20' => [],
                'ema_50' => [],
                'sma_20' => [],
                'atr' => 0,
                'volume_avg' => 0,
                'current_volume' => 0,
            ];
        }

        $closes = array_column($candles, 4);
        $highs = array_column($candles, 2);
        $lows = array_column($candles, 3);
        $volumes = array_column($candles, 5);

        return [
            'rsi' => $this->calculateRSI($closes),
            'macd' => $this->calculateMACD($closes),
            'ema_20' => $this->calculateEMA($closes, 20),
            'ema_50' => $this->calculateEMA($closes, 50),
            'sma_20' => $this->calculateSMA($closes, 20),
            'atr' => $this->calculateATR($highs, $lows, $closes),
            'volume_avg' => array_sum($volumes) / count($volumes),
            'current_volume' => end($volumes),
        ];
    }

    /**
     * Calculate RSI (Relative Strength Index)
     */
    private function calculateRSI($closes, $period = 14)
    {
        if (count($closes) < $period + 1) {
            return 50;
        }

        $changes = [];
        for ($i = 1; $i < count($closes); $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        $gains = $losses = [];
        foreach ($changes as $change) {
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) return 100;
        
        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    /**
     * Calculate MACD
     */
    private function calculateMACD($closes)
    {
        $ema12 = $this->calculateEMA($closes, 12);
        $ema26 = $this->calculateEMA($closes, 26);
        
        if (empty($ema12) || empty($ema26)) {
            return ['macd_line' => 0, 'signal_line' => 0, 'histogram' => 0];
        }
        
        $macdLine = end($ema12) - end($ema26);
        $signalData = array_slice($closes, -9);
        $signalLine = $this->calculateEMA($signalData, 9);
        
        return [
            'macd_line' => $macdLine,
            'signal_line' => !empty($signalLine) ? end($signalLine) : 0,
            'histogram' => $macdLine - (!empty($signalLine) ? end($signalLine) : 0)
        ];
    }

    /**
     * Calculate EMA (Exponential Moving Average)
     */
    private function calculateEMA($data, $period)
    {
        if (count($data) < $period) {
            return [];
        }

        $ema = [];
        $multiplier = 2 / ($period + 1);
        
        $sma = array_sum(array_slice($data, 0, $period)) / $period;
        $ema[] = $sma;
        
        for ($i = $period; $i < count($data); $i++) {
            $ema[] = ($data[$i] * $multiplier) + ($ema[count($ema)-1] * (1 - $multiplier));
        }
        
        return $ema;
    }

    /**
     * Calculate SMA (Simple Moving Average)
     */
    private function calculateSMA($data, $period)
    {
        $sma = [];
        for ($i = $period - 1; $i < count($data); $i++) {
            $sma[] = array_sum(array_slice($data, $i - $period + 1, $period)) / $period;
        }
        return $sma;
    }

    /**
     * Calculate ATR (Average True Range)
     */
    private function calculateATR($highs, $lows, $closes, $period = 14)
    {
        if (count($highs) < $period + 1) {
            return 0;
        }

        $trueRanges = [];
        for ($i = 1; $i < count($highs); $i++) {
            $tr1 = $highs[$i] - $lows[$i];
            $tr2 = abs($highs[$i] - $closes[$i - 1]);
            $tr3 = abs($lows[$i] - $closes[$i - 1]);
            $trueRanges[] = max($tr1, $tr2, $tr3);
        }
        
        return array_sum(array_slice($trueRanges, 0, $period)) / $period;
    }

    /**
     * Volume analysis
     */
    private function getVolumeAnalysis($candles)
    {
        $volumes = array_column($candles, 5);
        $currentVolume = end($volumes);
        $averageVolume = array_sum($volumes) / count($volumes);
        
        return [
            'current_volume' => $currentVolume,
            'average_volume' => $averageVolume,
            'volume_ratio' => $averageVolume > 0 ? $currentVolume / $averageVolume : 1
        ];
    }

    /**
     * Get multiple symbols data untuk AI analysis
     */
    public function getMultipleMarketData($symbols = ['BTC', 'ETH'], $interval = '1h', $limit = 100)
    {
        $this->info("Getting market data for multiple symbols: " . implode(', ', $symbols));
        
        $marketData = [];
        $successCount = 0;
        
        foreach ($symbols as $symbol) {
            $data = $this->getMarketSummary($symbol, $interval, $limit);
            if ($data) {
                $marketData[$symbol] = $data;
                $successCount++;
            }
        }
        
        $this->info("✅ Successfully fetched data for {$successCount}/" . count($symbols) . " symbols");
        return $marketData;
    }

    /**
     * Get exchange info untuk validasi symbol
     */
    public function getExchangeInfo()
    {
        try {
            $response = Http::timeout(10)
                ->get($this->baseUrl . '/exchangeInfo');
                
            if ($response->successful()) {
                return $response->json();
            }
            return null;
        } catch (Exception $e) {
            $this->error('Exchange info error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Helper methods untuk logging
     */
    private function info($message)
    {
        Log::info($message);
        if (app()->runningInConsole()) {
            echo $message . "\n";
        }
    }

    private function error($message)
    {
        Log::error($message);
        if (app()->runningInConsole()) {
            echo $message . "\n";
        }
    }
}