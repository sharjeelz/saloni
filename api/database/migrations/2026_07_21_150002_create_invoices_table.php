<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();            // e.g. GLOW-000001
            $table->string('status')->default('paid');     // paid | unpaid | void
            $table->string('description')->nullable();
            $table->decimal('subtotal', 10, 2);            // ex-VAT
            $table->decimal('vat_rate', 4, 2);             // 0.15
            $table->decimal('vat_amount', 10, 2);
            $table->decimal('total', 10, 2);               // incl VAT
            $table->char('currency', 3)->default('SAR');
            $table->string('seller_name');
            $table->string('seller_vat_number')->nullable();
            $table->string('buyer_name');
            $table->string('gateway_reference')->nullable();
            $table->text('zatca_qr')->nullable();          // base64 TLV payload
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['salon_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
