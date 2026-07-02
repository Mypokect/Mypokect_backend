<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El modelo SavingGoal y SavingGoalController escriben `cuenta_asociada`,
 * pero ninguna migración creaba la columna (solo existía `money_location`).
 * Eso hacía fallar la creación de metas con SQLSTATE[42S22] (columna inexistente).
 * Se agrega como nullable para que el INSERT del controlador funcione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saving_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('saving_goals', 'cuenta_asociada')) {
                $table->string('cuenta_asociada', 100)->nullable()->after('money_location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('saving_goals', function (Blueprint $table) {
            if (Schema::hasColumn('saving_goals', 'cuenta_asociada')) {
                $table->dropColumn('cuenta_asociada');
            }
        });
    }
};
