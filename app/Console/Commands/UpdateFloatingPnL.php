<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TradingExecutionService;

class UpdateFloatingPnL extends Command
{
    protected $signature = 'trading:update-pnl';
    protected $description = 'Update floating PNL for all open positions';

    public function handle(TradingExecutionService $executionService)
    {
        $this->info('📊 Updating floating PNL for all open positions...');
        
        $executionService->updateAllFloatingPnL();
        
        $this->info('✅ Floating PNL updated successfully!');
        return Command::SUCCESS;
    }
}