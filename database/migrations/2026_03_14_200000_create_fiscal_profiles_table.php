<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('patrimonio', 18, 2)->default(0);
            $table->unsignedTinyInteger('dependientes')->default(0);
            $table->decimal('deduc_salud', 18, 2)->default(0);
            $table->decimal('deduc_vivienda', 18, 2)->default(0);
            $table->decimal('retenciones', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};
