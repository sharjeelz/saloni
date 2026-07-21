<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');                 // primary identity in KSA
            $table->string('email')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();

            $table->unique(['salon_id', 'phone']);
            $table->index('salon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
