<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRiskAdjustmentToAiDecisionsTable extends Migration
{
    public function up()
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            // Tambahkan kolom yang missing
            if (!Schema::hasColumn('ai_decisions', 'risk_adjustment')) {
                $table->decimal('risk_adjustment', 5, 2)->default(1.00)->after('confidence');
            }
            
            if (!Schema::hasColumn('ai_decisions', 'volume_adjustment')) {
                $table->integer('volume_adjustment')->nullable()->after('risk_adjustment');
            }
            
            if (!Schema::hasColumn('ai_decisions', 'market_context')) {
                $table->json('market_context')->nullable()->after('volume_adjustment');
            }
        });
    }

    public function down()
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropColumn(['risk_adjustment', 'volume_adjustment', 'market_context']);
        });
    }
}