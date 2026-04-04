<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->string('rent_type', 20)
                  ->nullable()
                  ->after('is_business_expense')
                  ->comment('Bolsa de renta: laboral | honorarios | capital | comercial | otros');
            $table->index(['user_id', 'rent_type', 'type'], 'movements_rent_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex('movements_rent_type_idx');
            $table->dropColumn('rent_type');
        });
    }
};
