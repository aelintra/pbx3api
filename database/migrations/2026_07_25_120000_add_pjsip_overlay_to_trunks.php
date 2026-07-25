<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GenAst C2: thin PJSIP trunk overlay on the trunk row (home of record).
     */
    public function up(): void
    {
        if (! Schema::hasTable('trunks')) {
            return;
        }
        if (Schema::hasColumn('trunks', 'pjsip_overlay')) {
            return;
        }
        Schema::table('trunks', function (Blueprint $table) {
            $table->text('pjsip_overlay')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('trunks')) {
            return;
        }
        if (! Schema::hasColumn('trunks', 'pjsip_overlay')) {
            return;
        }
        Schema::table('trunks', function (Blueprint $table) {
            $table->dropColumn('pjsip_overlay');
        });
    }
};
