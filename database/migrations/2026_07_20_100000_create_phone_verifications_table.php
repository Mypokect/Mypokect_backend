<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación de propiedad de números de teléfono vía OTP.
 *
 * Cada fila es UN código emitido: al generar uno nuevo los anteriores del
 * mismo teléfono se invalidan (expira_en = now). `codigo` guarda el HASH del
 * OTP, nunca el valor en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telefono', 20);
            $table->string('codigo');
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->boolean('verificado')->default(false);
            $table->timestamp('expira_en');
            $table->timestamp('enviado_en');
            $table->timestamps();

            // Búsqueda del código activo por teléfono y del estado verificado.
            $table->index(['telefono', 'verificado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
