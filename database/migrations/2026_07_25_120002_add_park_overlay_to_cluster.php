<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GenAst C2: thin parking_lot overlay on the tenant (cluster) row (home of record).
     */
    public function up(): void
    {
        if (! Schema::hasTable('cluster')) {
            return;
        }
        if (Schema::hasColumn('cluster', 'park_overlay')) {
            return;
        }
        Schema::table('cluster', function (Blueprint $table) {
            $table->text('park_overlay')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cluster')) {
            return;
        }
        if (! Schema::hasColumn('cluster', 'park_overlay')) {
            return;
        }
        Schema::table('cluster', function (Blueprint $table) {
            $table->dropColumn('park_overlay');
        });
    }
};
