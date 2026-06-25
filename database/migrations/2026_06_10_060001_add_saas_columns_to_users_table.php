<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0 — Columnas SaaS aditivas en users.
 *
 * IMPORTANTE: la autenticación actual es phone+password (sin email). Estas columnas
 * son OPCIONALES y nullable: NO rompen el login por teléfono existente en Flutter.
 * El email se solicita/verifica solo cuando el usuario entra al flujo de pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->unique()->after('name');
            }
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 5)->default('es')->after('email_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['email', 'email_verified_at', 'locale'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
