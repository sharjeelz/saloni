<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('cancelled_by')->nullable()->after('cancelled_at');   // customer | owner | staff
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancellation_reason']);
        });
    }
};
