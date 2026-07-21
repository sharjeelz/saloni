<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan');                       // basic | pro
            $table->string('status')->default('trialing'); // trialing|active|past_due|canceled
            $table->decimal('price', 10, 2)->default(0);   // ex-VAT, per interval
            $table->char('currency', 3)->default('SAR');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable(); // last charge id / token
            $table->string('card_brand')->nullable();        // mada | visa | mastercard
            $table->string('card_last4', 4)->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
