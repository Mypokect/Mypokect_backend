<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de usuario EXCLUSIVO del panel de administración.
 * El login admin ya no usa el teléfono: usa este alias (+ clave + SMS).
 * Solo lo tienen los administradores; para el resto queda null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_username', 40)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_username');
        });
    }
};
