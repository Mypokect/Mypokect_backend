<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Budgets - Presupuestos con IA
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->enum('mode', ['manual', 'ai'])->default('manual');
            $table->string('language', 2)->default('es');
            $table->enum('plan_type', ['travel', 'event', 'party', 'purchase', 'project', 'other'])->default('other');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });

        // Budget Categories - Categorías del presupuesto
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->decimal('amount', 15, 2);
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
        Schema::dropIfExists('budgets');
    }
};
