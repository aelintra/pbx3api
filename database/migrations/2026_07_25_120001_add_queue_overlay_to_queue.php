<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GenAst C2: thin queue.conf overlay on the queue row (home of record).
     */
    public function up(): void
    {
        if (! Schema::hasTable('queue')) {
            return;
        }
        if (Schema::hasColumn('queue', 'queue_overlay')) {
            return;
        }
        Schema::table('queue', function (Blueprint $table) {
            $table->text('queue_overlay')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('queue')) {
            return;
        }
        if (! Schema::hasColumn('queue', 'queue_overlay')) {
            return;
        }
        Schema::table('queue', function (Blueprint $table) {
            $table->dropColumn('queue_overlay');
        });
    }
};
