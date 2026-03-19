<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'has_active_challenge')) {
                $table->boolean('has_active_challenge')->default(false)->after('savings_mode_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'has_active_challenge')) {
                $table->dropColumn('has_active_challenge');
            }
        });
    }
};
