<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pivote de pasarela: nequi/mercadopago -> wompi (única pasarela).
 *
 * Para la BD MySQL de dev ya migrada en F0. No hay filas reales de billing,
 * así que el ALTER de enums es seguro. En sqlite (tests) los enums son TEXT y
 * esta migración no aplica los ALTER nativos: el esquema final ya lo provee la
 * migración original (que también fue actualizada a wompi).
 *
 * Idempotente: revisa la conexión y la existencia de columnas/enum antes de tocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Columna nueva: reference (mapea webhook -> billing_payment)
        if (! Schema::hasColumn('billing_payments', 'reference')) {
            Schema::table('billing_payments', function ($table) {
                $table->string('reference')->nullable()->after('gateway_payment_id');
                $table->index('reference');
            });
        }

        // Los ALTER de enum son específicos de MySQL/MariaDB.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Normaliza cualquier fila previa (por si existiera) antes de estrechar el enum.
        DB::table('subscriptions')->whereIn('gateway', ['nequi', 'mercadopago'])->update(['gateway' => 'wompi']);
        DB::table('billing_payments')->whereIn('gateway', ['nequi', 'mercadopago'])->update(['gateway' => 'wompi']);
        DB::table('payment_methods')->whereIn('gateway', ['nequi', 'mercadopago'])->update(['gateway' => 'wompi']);

        DB::statement("ALTER TABLE subscriptions MODIFY gateway ENUM('wompi','manual') NULL");
        DB::statement("ALTER TABLE billing_payments MODIFY gateway ENUM('wompi','manual') NOT NULL");
        DB::statement("ALTER TABLE billing_payments MODIFY method ENUM('card','pse','nequi','bancolombia_transfer','bancolombia_qr','daviplata','cash') NULL");
        DB::statement("ALTER TABLE payment_methods MODIFY gateway ENUM('wompi','manual') NOT NULL");
        DB::statement("ALTER TABLE payment_methods MODIFY type ENUM('card','nequi') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_payments', 'reference')) {
            Schema::table('billing_payments', function ($table) {
                $table->dropIndex(['reference']);
                $table->dropColumn('reference');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE subscriptions MODIFY gateway ENUM('nequi','mercadopago','manual') NULL");
        DB::statement("ALTER TABLE billing_payments MODIFY gateway ENUM('nequi','mercadopago','manual') NOT NULL");
        DB::statement("ALTER TABLE billing_payments MODIFY method ENUM('nequi','pse','card','cash') NULL");
        DB::statement("ALTER TABLE payment_methods MODIFY gateway ENUM('nequi','mercadopago','manual') NOT NULL");
        DB::statement("ALTER TABLE payment_methods MODIFY type ENUM('nequi','card','pse') NOT NULL");
    }
};
