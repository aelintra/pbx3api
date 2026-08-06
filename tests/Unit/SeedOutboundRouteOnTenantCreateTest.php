<?php

uses(Tests\TestCase::class);

use App\Models\Route;
use App\Models\Tenant;
use App\Services\Tenant\SeedOutboundRouteOnTenantCreate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @var string|null */
$dbPath = null;

beforeEach(function () use (&$dbPath) {
    $dbPath = tempnam(sys_get_temp_dir(), 'pbx3seed');
    if ($dbPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $dbPath]);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('globals', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('pkey')->nullable();
        $table->text('default_outbound_dialplan')->nullable();
        $table->string('mycommit')->nullable();
    });
    Schema::create('route', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster')->nullable();
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->text('dialplan')->nullable();
        $table->string('path1')->nullable();
        $table->string('path2')->nullable();
        $table->string('path3')->nullable();
        $table->string('path4')->nullable();
        $table->string('active')->nullable();
        $table->string('auth')->nullable();
        $table->string('strategy')->nullable();
    });
    Schema::create('trunks', function (Blueprint $table) {
        $table->string('pkey')->primary();
        $table->string('cluster')->nullable();
        $table->string('active')->nullable();
    });

    DB::table('globals')->insert([
        'id' => 'testglobalsksuid00000000001',
        'pkey' => 'global',
        'default_outbound_dialplan' => '_0. _00.',
        'mycommit' => 'NO',
    ]);
});

afterEach(function () use (&$dbPath) {
    if ($dbPath !== null && is_file($dbPath)) {
        @unlink($dbPath);
    }
});

test('seeds MainOut from globals dialplan string', function () {
    config(['pbx3_fleet.mode' => true]);
    config(['pbx3_fleet.egress_trunk_pkey' => 'Egress']);
    DB::table('trunks')->insert(['pkey' => 'Egress', 'cluster' => 'default', 'active' => 'YES']);

    $tenant = new Tenant;
    $tenant->shortuid = 'tenanta1';
    $tenant->pkey = 'TenantA';

    $route = app(SeedOutboundRouteOnTenantCreate::class)->seed($tenant);

    expect($route)->not->toBeNull();
    expect($route->pkey)->toBe('MainOut');
    expect($route->cluster)->toBe('tenanta1');
    expect($route->dialplan)->toBe('_0. _00.');
    expect($route->path1)->toBe('Egress');
});

test('skips seed when globals dialplan empty', function () {
    DB::table('globals')->update(['default_outbound_dialplan' => '']);

    $tenant = new Tenant;
    $tenant->shortuid = 'tenantb1';
    $tenant->pkey = 'TenantB';

    expect(app(SeedOutboundRouteOnTenantCreate::class)->seed($tenant))->toBeNull();
    expect(Route::where('cluster', 'tenantb1')->count())->toBe(0);
});

test('skips seed when tenant already has routes', function () {
    $existing = new Route;
    $existing->id = 'existingrouteksuid0000000001';
    $existing->shortuid = 'route001';
    $existing->pkey = 'Custom';
    $existing->cluster = 'tenantc1';
    $existing->dialplan = '_9.';
    $existing->save();

    $tenant = new Tenant;
    $tenant->shortuid = 'tenantc1';
    $tenant->pkey = 'TenantC';

    expect(app(SeedOutboundRouteOnTenantCreate::class)->seed($tenant))->toBeNull();
    expect(Route::where('cluster', 'tenantc1')->count())->toBe(1);
});
