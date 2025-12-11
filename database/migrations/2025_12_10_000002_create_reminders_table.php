<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title', 120);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('category', 60)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('due_date'); // Stored in UTC
            $table->string('timezone', 64)->default('America/Bogota');
            $table->enum('recurrence', ['none', 'monthly'])->default('none');
            $table->json('recurrence_params')->nullable(); // e.g., {"dayOfMonth": 15}
            $table->integer('notify_offset_minutes')->default(1440); // 1 day before
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['user_id', 'due_date']);
            $table->index(['status', 'due_date']);
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
