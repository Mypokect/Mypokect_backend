<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tags - Categorías para organizar movimientos
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            // Prevenir duplicados por usuario
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'usage_count']);
        });

        // Movements - Gastos e ingresos diarios
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->nullable()->constrained()->onDelete('set null');
            $table->string('description');
            $table->decimal('amount', 15, 2); // Precisión para dinero
            $table->enum('type', ['expense', 'income'])->default('expense');
            $table->enum('payment_method', ['cash', 'digital'])->default('digital');
            $table->boolean('has_invoice')->default(false);
            $table->timestamps();

            // Índices para queries comunes
            $table->index(['user_id', 'created_at']); // Listar por fecha
            $table->index(['user_id', 'type']); // Filtrar por tipo
            $table->index(['user_id', 'tag_id']); // Agrupar por categoría
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
        Schema::dropIfExists('tags');
    }
};
