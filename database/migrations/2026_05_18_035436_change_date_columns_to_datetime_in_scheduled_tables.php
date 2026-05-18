<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade to datetime so specific payment times are stored and
        // propagated to every generated occurrence.
        Schema::table('scheduled_transactions', function (Blueprint $table) {
            $table->dateTime('start_date')->nullable()->change();
            $table->dateTime('end_date')->nullable()->change();
        });

        Schema::table('transaction_occurrences', function (Blueprint $table) {
            $table->dateTime('due_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_transactions', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });

        Schema::table('transaction_occurrences', function (Blueprint $table) {
            $table->date('due_date')->nullable()->change();
        });
    }
};
