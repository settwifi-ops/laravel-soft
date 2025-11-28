<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TradingAnalysisService;
use App\Services\OpenAIService;

class UpdateSignalAnalysisCommand extends Command
{
    protected $signature = 'signals:analyze 
                            {--limit=50 : Maximum signals to process}
                            {--symbol= : Process specific symbol}
                            {--id= : Process specific signal ID}
                            {--force : Process even if inactive}
                            {--reanalyze : Force re-analysis even if counts same}
                            {--test : Test OpenAI connection first}
                            {--all : Process all signals ignoring conditions}';
    
    protected $description = 'Analyze signals with AI when appearance_count increases';
    protected $tradingAnalysisService;

    // ✅ GUNAKAN DEPENDENCY INJECTION
    public function __construct(TradingAnalysisService $tradingAnalysisService)
    {
        parent::__construct();
        $this->tradingAnalysisService = $tradingAnalysisService;
    }

    public function handle()
    {

        $limit = $this->option('limit');
        
        $this->info("Starting AI analysis for {$limit} signals...");
        
        // ✅ SEKARANG BISA PAKAI SERVICE DENGAN BENAR
        $signals = $this->tradingAnalysisService->getSignalsNeedingUpdate($limit);        // Test OpenAI connection jika option dipilih
        if ($this->option('test')) {
            $this->testOpenAIConnection();
            return;
        }

        $analysisService = new TradingAnalysisService();
        
        $this->info('🔍 Scanning for signals needing AI analysis...');

        // Handle --all option
        if ($this->option('all')) {
            $this->info("🎯 Processing ALL signals (ignoring conditions)...");
            $signals = $analysisService->getAllSignals($this->option('limit'));
            
            if ($signals->isEmpty()) {
                $this->info('✅ No signals found in database');
                return;
            }

            $this->info("📈 Processing {$signals->count()} signals...");
            
            $signalIds = $signals->pluck('id')->toArray();
            $results = $analysisService->processSignalBatch($signalIds);

            $this->showResults($results);
            return;
        }

        // Handle specific symbol or ID
        if ($symbol = $this->option('symbol')) {
            $this->processSpecificSymbol($analysisService, $symbol);
            return;
        }

        if ($id = $this->option('id')) {
            $this->processSpecificId($analysisService, $id);
            return;
        }

        // Batch process
        $signals = $analysisService->getSignalsNeedingUpdate($this->option('limit'));

        if ($signals->isEmpty()) {
            $this->info('✅ No signals need analysis at this time');
            $this->info('💡 Use --reanalyze to force analysis or check if signals are active');
            return;
        }

        $this->info("📈 Found {$signals->count()} signals needing analysis");
        
        $signalIds = $signals->pluck('id')->toArray();
        $results = $analysisService->processSignalBatch($signalIds);

        $this->showResults($results);
    }

    private function testOpenAIConnection()
    {
        $this->info('🧪 Testing OpenAI Connection...');
        
        try {
            $openAIService = new OpenAIService();
            $success = $openAIService->testConnection();
            
            if ($success) {
                $this->info('✅ OpenAI Connection: SUCCESS');
                $this->info('🎯 Ready to analyze signals!');
            } else {
                $this->error('❌ OpenAI Connection: FAILED');
                $this->info('💡 Check your API key and internet connection');
            }
        } catch (Exception $e) {
            $this->error('❌ OpenAI Test Error: ' . $e->getMessage());
        }
    }

    private function processSpecificSymbol($analysisService, $symbol)
    {
        $this->info("🎯 Processing specific symbol: {$symbol}");
        
        // Try different symbol formats
        $formatsToTry = [
            strtolower($symbol),
            strtoupper($symbol),
            $symbol
        ];

        foreach ($formatsToTry as $format) {
            $this->info("Trying symbol format: '{$format}'");
            
            // Include inactive signals if --force flag
            $signal = \App\Models\Signal::where('symbol', $format);
            
            if (!$this->option('force')) {
                $signal->where('is_active_signal', true);
            }
            
            $signal = $signal->first();

            if ($signal) {
                $this->info("✅ Found signal with ID: {$signal->id}");
                $this->info("Active: " . ($signal->is_active_signal ? 'Yes' : 'No'));
                $this->info("Appearance Count: {$signal->appearance_count}");
                $this->info("Last Summary Count: " . ($signal->last_summary_count ?? 'NULL'));
                
                // Use force reanalyze jika option dipilih
                $forceReanalyze = $this->option('reanalyze');
                if ($forceReanalyze) {
                    $this->info("🔁 FORCE RE-ANALYSIS enabled");
                }
                
                $success = $analysisService->processSignal($signal->id, $forceReanalyze);
                
                if ($success === true) {
                    $this->info("✅ Successfully analyzed {$signal->symbol}");
                    
                    // Show updated data
                    $updatedSignal = \App\Models\Signal::find($signal->id);
                    $this->showSignalAnalysis($updatedSignal);
                    return;
                } elseif ($success === 'skipped') {
                    $this->warn("⏭️ Analysis skipped for {$signal->symbol} - no update needed");
                    $this->info("💡 Use --reanalyze to force analysis");
                    return;
                } else {
                    $this->error("❌ Failed to analyze {$signal->symbol}");
                    return;
                }
            }
        }

        $this->error("❌ Signal not found for symbol: {$symbol}");
        $this->showAvailableSymbols();
    }

    private function processSpecificId($analysisService, $id)
    {
        $this->info("🎯 Processing specific signal ID: {$id}");
        
        $signal = \App\Models\Signal::find($id);
        
        if (!$signal) {
            $this->error("❌ Signal ID {$id} not found");
            $this->showAvailableSymbols();
            return;
        }

        $this->info("Symbol: {$signal->symbol}");
        $this->info("Active: " . ($signal->is_active_signal ? 'Yes' : 'No'));
        $this->info("Appearance Count: {$signal->appearance_count}");
        $this->info("Last Summary Count: " . ($signal->last_summary_count ?? 'NULL'));
        
        // Use force reanalyze jika option dipilih
        $forceReanalyze = $this->option('reanalyze');
        if ($forceReanalyze) {
            $this->info("🔁 FORCE RE-ANALYSIS enabled");
        }
        
        $success = $analysisService->processSignal($id, $forceReanalyze);
        
        if ($success === true) {
            $this->info("✅ Successfully analyzed signal ID: {$id}");
            
            // Show updated data
            $updatedSignal = \App\Models\Signal::find($id);
            $this->showSignalAnalysis($updatedSignal);
            
        } elseif ($success === 'skipped') {
            $this->warn("⏭️ Analysis skipped for {$signal->symbol} - no update needed");
            $this->info("💡 Use --reanalyze to force analysis");
        } else {
            $this->error("❌ Failed to analyze signal ID: {$id}");
            $this->info("💡 Use --test to check OpenAI connection");
        }
    }

    private function showSignalAnalysis($signal)
    {
        $this->info("\n📊 UPDATED ANALYSIS:");
        $this->info("====================");
        $this->info("Summary: " . ($signal->ai_summary ?? 'N/A'));
        $this->info("Probability: " . ($signal->ai_probability ?? 'N/A') . '%');
        $this->info("Support: " . ($signal->support_level ?? 'N/A'));
        $this->info("Resistance: " . ($signal->resistance_level ?? 'N/A'));
        $this->info("Market Structure: " . ($signal->market_structure ?? 'N/A'));
        $this->info("Trend Power: " . ($signal->trend_power ?? 'N/A'));
        $this->info("Momentum: " . ($signal->momentum_category ?? 'N/A'));
        $this->info("Funding: " . ($signal->funding_direction ?? 'N/A'));
        $this->info("Whales: " . ($signal->whale_behavior ?? 'N/A'));
    }

    private function showAvailableSymbols()
    {
        $availableSymbols = \App\Models\Signal::where('is_active_signal', true)
            ->pluck('symbol')
            ->take(10)
            ->toArray();
            
        if (!empty($availableSymbols)) {
            $this->info("\n🔍 Available active symbols: " . implode(', ', $availableSymbols));
        } else {
            $allSymbols = \App\Models\Signal::pluck('symbol')
                ->take(10)
                ->toArray();
            $this->info("\n🔍 All symbols: " . implode(', ', $allSymbols));
            $this->info("💡 Use --force to process inactive signals");
        }
    }

    private function showResults($results)
    {
        $this->info("\n🎯 BATCH PROCESSING RESULTS:");
        $this->info("============================");
        $this->info("✅ Successfully processed: {$results['processed']}");
        $this->info("⏭️ Skipped (no update needed): {$results['skipped']}"); 
        $this->info("❌ Failed: {$results['failed']}");
        
        if ($results['processed'] > 0) {
            $this->info("\n📋 Successful analyses:");
            foreach ($results['details'] as $signalId => $status) {
                if ($status === 'success') {
                    $signal = \App\Models\Signal::find($signalId);
                    $this->info("  ✅ {$signal->symbol} (ID: {$signalId})");
                }
            }
        }
    }
}