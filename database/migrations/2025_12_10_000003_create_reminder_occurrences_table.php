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
        Schema::create('reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->onDelete('cascade');
            $table->dateTime('occurrence_date'); // UTC
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamps();
            
            $table->unique(['reminder_id', 'occurrence_date']);
            $table->index(['reminder_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_occurrences');
    }
};
