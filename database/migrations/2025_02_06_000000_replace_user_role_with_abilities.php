<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add user-level abilities (JSON array). Tokens get these abilities at login.
     * Source of truth for what a user can do; no role column used.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};
