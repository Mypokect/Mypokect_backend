<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->decimal('amount', 10, 2);
            $table->enum('type', ['expense', 'income']);
            $table->string('category')->nullable();
            $table->date('start_date');
            $table->enum('recurrence_type', ['none', 'daily', 'weekly', 'monthly', 'yearly'])->default('none');
            $table->integer('recurrence_interval')->default(1);
            $table->date('end_date')->nullable();
            $table->integer('reminder_days_before')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_transactions');
    }
};
