<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Débito automático: bandera por suscripción (default ON). El cron
 * `subscriptions:renew` solo cobra automáticamente cuando `auto_renew = true`
 * y existe una fuente de pago tokenizada (`gateway_subscription_id`).
 *
 * También añade `renewal_reminded_at` para deduplicar los recordatorios de
 * vencimiento (Fase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->boolean('auto_renew')->default(true)->after('gateway_subscription_id');
            }
            if (! Schema::hasColumn('subscriptions', 'renewal_reminded_at')) {
                $table->dateTime('renewal_reminded_at')->nullable()->after('grace_ends_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->dropColumn('auto_renew');
            }
            if (Schema::hasColumn('subscriptions', 'renewal_reminded_at')) {
                $table->dropColumn('renewal_reminded_at');
            }
        });
    }
};
