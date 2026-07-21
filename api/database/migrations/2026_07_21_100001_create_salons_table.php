<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();          // used for book.app/{slug}
            $table->string('phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->string('timezone')->default('Asia/Riyadh');
            $table->string('locale', 5)->default('ar');
            $table->string('plan')->default('trial');   // trial | basic | pro
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salons');
    }
};
