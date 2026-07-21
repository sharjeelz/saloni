<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // Bind a code to its purpose + salon so a login code can't be used
            // to book, and a code minted on salon A can't verify on salon B.
            $table->string('purpose')->default('login')->after('channel');
            $table->unsignedBigInteger('salon_id')->nullable()->after('purpose');
            $table->index(['destination', 'channel', 'purpose', 'salon_id']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['destination', 'channel', 'purpose', 'salon_id']);
            $table->dropColumn(['purpose', 'salon_id']);
        });
    }
};
