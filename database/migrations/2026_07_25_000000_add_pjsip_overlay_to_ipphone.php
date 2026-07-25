<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GenAst C2: thin PJSIP phone overlay on the extension row (home of record).
     * Commit merges tmpl + this text; optional file under endpoints/ is fallback only.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ipphone')) {
            return;
        }
        if (Schema::hasColumn('ipphone', 'pjsip_overlay')) {
            return;
        }
        Schema::table('ipphone', function (Blueprint $table) {
            $table->text('pjsip_overlay')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ipphone')) {
            return;
        }
        if (! Schema::hasColumn('ipphone', 'pjsip_overlay')) {
            return;
        }
        Schema::table('ipphone', function (Blueprint $table) {
            $table->dropColumn('pjsip_overlay');
        });
    }
};
