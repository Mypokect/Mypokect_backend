<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transaction_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_transaction_id')->constrained()->onDelete('cascade');
            $table->date('payment_date');
            $table->boolean('is_paid')->default(true);
            $table->timestamps();

            // CORRECCIÓN: Le damos un nombre corto al índice.
            $table->unique(['scheduled_transaction_id', 'payment_date'], 'trans_conf_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_confirmations');
    }
};
