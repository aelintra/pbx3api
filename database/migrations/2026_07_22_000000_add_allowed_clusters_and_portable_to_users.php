<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instance user privileges P1: cluster scope + portable flag for tenant move (P4).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('allowed_clusters')->nullable()->after('abilities');
            $table->boolean('portable')->default(true)->after('allowed_clusters');
        });

        // Existing admin users are instance-local (not portable with a tenant).
        try {
            $users = DB::table('users')->select(['id', 'abilities'])->get();
            foreach ($users as $row) {
                $abilities = json_decode((string) $row->abilities, true);
                if (is_array($abilities) && in_array('admin', $abilities, true)) {
                    DB::table('users')->where('id', $row->id)->update(['portable' => false]);
                }
            }
        } catch (\Throwable) {
            // abilities column may be absent on fresh installs before prior migration
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['allowed_clusters', 'portable']);
        });
    }
};
