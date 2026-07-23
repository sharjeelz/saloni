<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            // Optional social profiles — shown on the public booking page when set.
            $table->string('instagram')->nullable()->after('brand_color');
            $table->string('facebook')->nullable()->after('instagram');
            $table->string('tiktok')->nullable()->after('facebook');
            $table->string('youtube')->nullable()->after('tiktok');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['instagram', 'facebook', 'tiktok', 'youtube']);
        });
    }
};
