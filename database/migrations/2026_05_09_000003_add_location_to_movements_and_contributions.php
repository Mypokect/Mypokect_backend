<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->boolean('is_digital')->default(true)->after('payment_method');
            $table->string('location_name', 100)->nullable()->after('is_digital');
        });

        Schema::table('goal_contributions', function (Blueprint $table) {
            $table->boolean('is_digital')->default(false)->after('description');
            $table->string('location_name', 100)->nullable()->after('is_digital');
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropColumn(['is_digital', 'location_name']);
        });

        Schema::table('goal_contributions', function (Blueprint $table) {
            $table->dropColumn(['is_digital', 'location_name']);
        });
    }
};
