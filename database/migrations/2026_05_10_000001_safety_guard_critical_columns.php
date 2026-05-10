<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety-guard migration.
 * Adds any critical column that might be missing after a bad merge.
 * Every statement is idempotent (hasColumn guard), so it can be run
 * multiple times without errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── movements ────────────────────────────────────────────────────────
        Schema::table('movements', function (Blueprint $table) {
            if (! Schema::hasColumn('movements', 'payment_method')) {
                $table->string('payment_method', 20)->default('cash')->after('type');
            }
            if (! Schema::hasColumn('movements', 'has_invoice')) {
                $table->boolean('has_invoice')->default(false)->after('payment_method');
            }
            if (! Schema::hasColumn('movements', 'is_business_expense')) {
                $table->boolean('is_business_expense')->default(false)->after('has_invoice');
            }
            if (! Schema::hasColumn('movements', 'rent_type')) {
                $table->string('rent_type', 20)->nullable()->after('is_business_expense');
            }
            if (! Schema::hasColumn('movements', 'is_digital')) {
                $table->boolean('is_digital')->default(true)->after('rent_type');
            }
            if (! Schema::hasColumn('movements', 'location_name')) {
                $table->string('location_name', 100)->nullable()->after('is_digital');
            }
        });

        // ── goal_contributions ───────────────────────────────────────────────
        Schema::table('goal_contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('goal_contributions', 'is_digital')) {
                $table->boolean('is_digital')->default(false)->after('description');
            }
            if (! Schema::hasColumn('goal_contributions', 'location_name')) {
                $table->string('location_name', 100)->nullable()->after('is_digital');
            }
        });

        // ── users ────────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'savings_mode_pct')) {
                $table->decimal('savings_mode_pct', 5, 4)->default(0)->after('fcm_token');
            }
            if (! Schema::hasColumn('users', 'has_active_challenge')) {
                $table->boolean('has_active_challenge')->default(false)->after('savings_mode_pct');
            }
            if (! Schema::hasColumn('users', 'challenge_savings_balance')) {
                $table->decimal('challenge_savings_balance', 15, 2)->default(0)->after('has_active_challenge');
            }
        });
    }

    public function down(): void
    {
        // Intentionally left blank — never drop safety-guard columns automatically.
    }
};
