<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // We need to recreate all the tenant-specific tables
        // This is just an example - you'll need to add all your tables
        
        // Recreate the ipphone table for extensions
        if (!Schema::hasTable('ipphone')) {
            Schema::create('ipphone', function (Blueprint $table) {
                $table->string('pkey')->primary();
                $table->string('cluster')->default('default');
                $table->string('active')->default('YES');
                $table->integer('abstimeout')->default(14400);
                $table->string('basemacaddr')->nullable();
                $table->string('callbackto')->default('desk');
                $table->string('devicerec')->default('default');
                $table->string('protocol')->default('IPV4');
                $table->string('provisionwith')->default('IP');
                $table->string('sndcreds')->default('Always');
                $table->string('transport')->default('udp');
                $table->string('technology')->default('SIP');
                // Add other ipphone fields
            });
        }
        
        // Recreate trunks table
        if (!Schema::hasTable('trunks')) {
            Schema::create('trunks', function (Blueprint $table) {
                $table->string('pkey')->primary();
                $table->string('cluster')->default('default');
                $table->string('active')->default('YES');
                $table->string('callprogress')->default('NO');
                $table->string('closeroute')->default('Operator');
                $table->string('faxdetect')->default('NO');
                $table->string('lcl')->default('NO');
                $table->string('moh')->default('NO');
                $table->string('monitor')->default('NO');
                $table->string('openroute')->default('Operator');
                $table->string('routeable')->default('NO');
                $table->integer('routeclassopen')->default(100);
                $table->integer('routeclassclosed')->default(100);
                $table->string('swoclip')->default('YES');
                // Add other trunks fields
            });
        }
        
        // Add all other tables needed for tenant-specific data
        // Make sure to include all the tables that were in the central database
        // but scoped to the tenant (via cluster column)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop all tenant tables
        Schema::dropIfExists('ipphone');
        Schema::dropIfExists('trunks');
        // Drop all other tenant tables
    }
};
