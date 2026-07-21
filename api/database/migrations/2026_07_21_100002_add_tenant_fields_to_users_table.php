<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('salon_id')->nullable()->after('id')
                ->constrained('salons')->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('role')->default('owner')->after('phone'); // super_admin | owner | staff
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('title')->nullable()->after('is_active');  // e.g. "Senior Stylist"

            $table->index(['salon_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salon_id');
            $table->dropColumn(['phone', 'role', 'is_active', 'title']);
        });
    }
};
