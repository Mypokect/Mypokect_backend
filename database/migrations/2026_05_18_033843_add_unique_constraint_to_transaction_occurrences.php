<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate rows keeping the one with the lowest id.
        // Written with the query builder (no raw JOIN-DELETE) so it works
        // on both MySQL (production) and SQLite (test suite).
        $duplicateIds = DB::table('transaction_occurrences as t1')
            ->join('transaction_occurrences as t2', function ($join) {
                $join->on('t1.scheduled_transaction_id', '=', 't2.scheduled_transaction_id')
                     ->on('t1.due_date', '=', 't2.due_date')
                     ->whereColumn('t1.id', '>', 't2.id');
            })
            ->pluck('t1.id')
            ->toArray();

        if (! empty($duplicateIds)) {
            DB::table('transaction_occurrences')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('transaction_occurrences', function (Blueprint $table) {
            // The existing plain composite index stays because MySQL uses it as
            // the backing index for the FK constraint.  The unique index below
            // is what enables safe upsert() calls.
            $table->unique(
                ['scheduled_transaction_id', 'due_date'],
                'transaction_occurrences_stid_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transaction_occurrences', function (Blueprint $table) {
            $table->dropUnique('transaction_occurrences_stid_date_unique');
        });
    }
};
