<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transaction_occurrences', function (Blueprint $table) {
            $table->id();

            // --- ¡LA LÍNEA QUE FALTA ESTÁ AQUÍ! ---
            // Le decimos a la tabla que cada ocurrencia pertenece a un usuario.
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->foreignId('scheduled_transaction_id')->constrained()->onDelete('cascade');
            $table->date('occurrence_date');
            $table->boolean('is_paid')->default(false);
            $table->timestamps();

            $table->unique(['scheduled_transaction_id', 'occurrence_date'], 'trans_occ_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_occurrences');
    }
};