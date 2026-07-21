<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Short, human-quotable booking code (e.g. R7K2QX). The unguessable
            // public_token stays reserved for the manage/cancel link.
            $table->string('reference', 12)->nullable()->unique()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
