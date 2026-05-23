<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds compound indexes that cover the most common query patterns:
 *
 *  - homeData / financialSummary aggregate movements by (user_id, type, created_at)
 *  - budget spending sums filter by (user_id, type, created_at) and join on tag_id
 *  - tax queries load all income movements for a year: (user_id, type, created_at)
 *
 * The existing single-column indexes (user_id, type), (user_id, created_at) cannot
 * satisfy these range-aggregate queries without a full index scan on one side.
 * The 3-column compound index lets MySQL resolve the whole query from the index tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            // Primary read pattern: WHERE user_id=? AND type=? AND created_at BETWEEN ? AND ?
            $table->index(['user_id', 'type', 'created_at'], 'movements_user_type_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex('movements_user_type_date_idx');
        });
    }
};
