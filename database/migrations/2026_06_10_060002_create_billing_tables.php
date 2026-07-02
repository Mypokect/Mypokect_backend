<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0 — Dominio Billing (SaaS).
 *
 * Convenciones:
 * - Dinero en COP guardado como BIGINT de "centavos" (price_cents). En COP no hay
 *   centavos reales, pero mantenemos el patrón cents para evitar floats. 4000 COP = 400000.
 *   (Para mostrar: price_cents / 100). Decisión consistente con decimal(15,2) del resto.
 * - Idempotencia de webhooks vía UNIQUE(gateway, gateway_payment_id) en payments.
 * - billing_transactions es un libro APPEND-ONLY (no se actualiza ni borra).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PLANES
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // free, pro_monthly, pro_annual
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('COP');
            $table->enum('interval', ['month', 'year', 'none'])->default('none');
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('features')->nullable();        // límites: max_voice_month, ia_budgets...
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // SUSCRIPCIONES
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->enum('status', ['trialing', 'active', 'past_due', 'canceled', 'expired', 'paused'])
                ->default('trialing');
            $table->enum('gateway', ['wompi', 'manual'])->nullable();
            $table->string('gateway_subscription_id')->nullable(); // Wompi: payment_source_id (auto-renovación)
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('grace_ends_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('current_period_end');
            $table->index('status');
        });

        // PAGOS DE BILLING (intentos individuales)
        // NOTA: se llama 'billing_payments' para NO colisionar con la tabla 'payments'
        // existente (recibos de recordatorios — modelo App\Models\Payment).
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('gateway', ['wompi', 'manual']);
            $table->string('gateway_payment_id')->nullable();   // id de la transacción en Wompi
            $table->string('reference')->nullable();            // referencia única enviada al checkout (mapea webhook -> pago)
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('COP');
            $table->enum('status', ['pending', 'approved', 'rejected', 'refunded', 'charged_back'])
                ->default('pending');
            $table->enum('method', ['card', 'pse', 'nequi', 'bancolombia_transfer', 'bancolombia_qr', 'daviplata', 'cash'])->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            // Idempotencia: un pago del gateway se procesa una sola vez
            $table->unique(['gateway', 'gateway_payment_id']);
            $table->index(['user_id', 'status']);
            $table->index('subscription_id');
            $table->index('reference');
        });

        // LIBRO MAYOR DE BILLING (append-only) — NO confundir con movements del usuario
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('billing_payments')->cascadeOnDelete();
            $table->enum('type', ['charge', 'refund', 'chargeback', 'adjustment']);
            $table->bigInteger('amount_cents');          // puede ser negativo (refund)
            $table->bigInteger('balance_after')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent(); // sin updated_at: inmutable
            $table->index(['payment_id', 'type']);
        });

        // MÉTODOS DE PAGO GUARDADOS (tokens recurrentes — token cifrado at-rest)
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('gateway', ['wompi', 'manual']);
            $table->enum('type', ['card', 'nequi']);      // fuentes de pago tokenizables en Wompi
            $table->text('token')->nullable();           // cifrado vía cast 'encrypted'
            $table->string('brand')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->string('holder_masked')->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });

        // FACTURAS / COMPROBANTES
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            $table->string('number')->unique();          // consecutivo
            $table->enum('status', ['issued', 'paid', 'void'])->default('issued');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('COP');
            $table->dateTime('issued_at')->nullable();
            $table->string('pdf_path')->nullable();
            // Facturación electrónica DIAN (si se integra)
            $table->string('dian_cufe')->nullable();
            $table->string('dian_status')->nullable();
            // Datos de facturación del comprador
            $table->string('billing_name')->nullable();
            $table->string('billing_doc_type')->nullable();
            $table->string('billing_doc_number')->nullable();
            $table->string('billing_email')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // WEBHOOKS (log + idempotencia + reintentos)
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_type')->nullable();
            $table->string('external_id')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload')->nullable();
            $table->enum('status', ['received', 'processed', 'failed', 'ignored'])->default('received');
            $table->dateTime('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['gateway', 'external_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('billing_transactions');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
