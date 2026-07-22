<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // A shareable map/directions link (e.g. Google Maps), kept separate
            // from the human-readable `address` text.
            $table->string('maps_url', 2048)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('maps_url');
        });
    }
};
