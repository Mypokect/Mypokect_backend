<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('name', 255);
            $table->decimal('amount', 12, 2);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->text('reason')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->index('budget_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_categories');
    }
};
