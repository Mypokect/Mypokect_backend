<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scheduled Transactions - Transacciones recurrentes
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->decimal('amount', 15, 2);
            $table->enum('type', ['expense', 'income']);
            $table->string('category')->nullable();
            $table->date('start_date');
            $table->enum('recurrence_type', ['none', 'daily', 'weekly', 'monthly', 'yearly'])->default('none');
            $table->integer('recurrence_interval')->default(1);
            $table->date('end_date')->nullable();
            $table->integer('reminder_days_before')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
            $table->index('recurrence_type');
        });

        // Transaction Occurrences - Instancias calculadas
        Schema::create('transaction_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_transaction_id')->constrained()->onDelete('cascade');
            $table->date('due_date');
            $table->boolean('is_paid')->default(false);
            $table->timestamps();

            $table->index(['scheduled_transaction_id', 'due_date']);
            $table->index('is_paid');
        });

        // Transaction Confirmations - Confirmaciones de pago
        Schema::create('transaction_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_occurrence_id')->constrained()->onDelete('cascade');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('transaction_occurrence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_confirmations');
        Schema::dropIfExists('transaction_occurrences');
        Schema::dropIfExists('scheduled_transactions');
    }
};
