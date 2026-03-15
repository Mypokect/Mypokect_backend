<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->boolean('is_business_expense')
                  ->default(false)
                  ->after('has_invoice')
                  ->comment('Gasto relacionado con la actividad económica (independiente/comerciante). Permite deducción 100%.');

            $table->index(['user_id', 'is_business_expense'], 'movements_user_business_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex('movements_user_business_idx');
            $table->dropColumn('is_business_expense');
        });
    }
};
