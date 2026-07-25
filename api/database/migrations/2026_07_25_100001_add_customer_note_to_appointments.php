<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Optional free-text the customer leaves at booking ("please use
            // fragrance-free products", "running 5 min late", etc.).
            $table->string('customer_note', 500)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('customer_note');
        });
    }
};
