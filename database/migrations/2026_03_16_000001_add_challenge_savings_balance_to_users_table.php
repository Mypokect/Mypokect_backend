<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'challenge_savings_balance')) {
                $table->decimal('challenge_savings_balance', 15, 2)
                      ->default(0)
                      ->after('has_active_challenge');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'challenge_savings_balance')) {
                $table->dropColumn('challenge_savings_balance');
            }
        });
    }
};
