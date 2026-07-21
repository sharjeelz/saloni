<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('channel');            // phone | email
            $table->string('destination');        // the phone number or email
            $table->string('code_hash');          // hashed 6-digit code, never stored plain
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['destination', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
