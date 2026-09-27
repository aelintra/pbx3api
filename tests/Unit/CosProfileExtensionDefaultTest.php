<?php

uses(Tests\TestCase::class);

use App\Http\Controllers\ExtensionController;
use App\Models\CosProfile;
use App\Models\Extension;
use App\Models\IpPhoneCosClosed;
use App\Models\IpPhoneCosOpen;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey')->nullable();
    });

    Schema::create('cos', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster');
        $table->string('defaultopen')->default('NO');
        $table->string('defaultclosed')->default('NO');
    });

    Schema::create('cos_profile', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster');
        $table->string('cname')->nullable();
        $table->string('is_default')->default('NO');
        $table->string('active')->default('YES');
    });

    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster')->nullable();
        $table->string('cos_profile')->nullable();
    });

    Schema::create('ipphonecosopen', function (Blueprint $table) {
        $table->string('ipphone_pkey');
        $table->string('cos_pkey');
        $table->string('cluster')->nullable();
    });

    Schema::create('ipphonecosclosed', function (Blueprint $table) {
        $table->string('ipphone_pkey');
        $table->string('cos_pkey');
        $table->string('cluster')->nullable();
    });

    DB::table('cluster')->insert([
        'id' => 'tenantaksuid00000000000001',
        'shortuid' => 'tenanta1',
        'pkey' => 'TenantA',
    ]);
});

test('create_default_cos_instances assigns tenant default profile and seeds junctions', function () {
    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'staff01',
        'pkey' => 'staff01',
        'cluster' => 'tenanta1',
        'cname' => 'Staff',
        'is_default' => 'YES',
        'active' => 'YES',
    ]);
    DB::table('cos')->insert([
        'id' => 'cosruleaksuid0000000000001',
        'shortuid' => 'cosrula1',
        'pkey' => 'Intl',
        'cluster' => 'tenanta1',
        'defaultopen' => 'YES',
        'defaultclosed' => 'YES',
    ]);
    DB::table('ipphone')->insert([
        'id' => 'extaksuid0000000000000001',
        'shortuid' => 'exta0001',
        'pkey' => '1001',
        'cluster' => 'tenanta1',
        'cos_profile' => null,
    ]);

    $ext = Extension::find('extaksuid0000000000000001');
    $method = new \ReflectionMethod(ExtensionController::class, 'create_default_cos_instances');
    $method->setAccessible(true);
    $method->invoke(new ExtensionController, $ext);

    expect(Extension::find($ext->id)->cos_profile)->toBe('staff01');
    expect(IpPhoneCosOpen::where('ipphone_pkey', '1001')->where('cos_pkey', 'Intl')->exists())->toBeTrue();
    expect(IpPhoneCosClosed::where('ipphone_pkey', '1001')->where('cos_pkey', 'Intl')->exists())->toBeTrue();
});

test('CosProfile::defaultPkeyForCluster returns default for aliases', function () {
    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'staff01',
        'pkey' => 'staff01',
        'cluster' => 'tenanta1',
        'cname' => 'Staff',
        'is_default' => 'YES',
        'active' => 'YES',
    ]);

    expect(CosProfile::defaultPkeyForCluster('tenanta1'))->toBe('staff01')
        ->and(CosProfile::defaultPkeyForCluster('TenantA'))->toBe('staff01')
        ->and(CosProfile::belongsToCluster('staff01', 'tenanta1'))->toBeTrue()
        ->and(CosProfile::belongsToCluster('staff01', 'tenantb1'))->toBeFalse();
});
