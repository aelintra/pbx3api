<?php

uses(Tests\TestCase::class);

use App\Services\Tenant\TenantMobilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @var string|null */
$dbPath = null;

beforeEach(function () use (&$dbPath) {
    $dbPath = tempnam(sys_get_temp_dir(), 'pbx3wipe');
    if ($dbPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    // openPdo() opens a separate connection; file sqlite shares state (:memory: does not).
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $dbPath]);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->unique();
        $table->string('pkey');
        $table->string('fqdn')->nullable();
    });
    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster');
        $table->string('pkey')->nullable();
    });
    Schema::create('inroutes', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster');
        $table->string('pkey')->nullable();
    });
});

afterEach(function () use (&$dbPath) {
    Schema::dropIfExists('inroutes');
    Schema::dropIfExists('ipphone');
    Schema::dropIfExists('dialalias');
    Schema::dropIfExists('cluster');
    DB::disconnect('sqlite');
    if (is_string($dbPath) && is_file($dbPath)) {
        @unlink($dbPath);
    }
    $dbPath = null;
});

test('destroyTenantData removes cluster-scoped rows and the cluster row', function () {
    DB::table('cluster')->insert([
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
        'fqdn' => 'vqcwd4.pbx3.com',
    ]);
    DB::table('ipphone')->insert([
        ['id' => 'phone-1', 'cluster' => 'vqcwd4', 'pkey' => '1000'],
        ['id' => 'phone-other', 'cluster' => 'other01', 'pkey' => '2000'],
    ]);
    DB::table('inroutes')->insert([
        'id' => 'route-1',
        'cluster' => 'vqcwd4',
        'pkey' => 'main',
    ]);

    $tenant = (object) [
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
    ];

    (new TenantMobilityService)->destroyTenantData($tenant);

    expect(DB::table('cluster')->where('id', 'cluster-ksuid-1')->exists())->toBeFalse()
        ->and(DB::table('ipphone')->where('cluster', 'vqcwd4')->count())->toBe(0)
        ->and(DB::table('inroutes')->where('cluster', 'vqcwd4')->count())->toBe(0)
        ->and(DB::table('ipphone')->where('id', 'phone-other')->exists())->toBeTrue();
});

test('countTenantData returns per-table wipe blast radius without mutating', function () {
    DB::table('cluster')->insert([
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
        'fqdn' => 'vqcwd4.pbx3.com',
    ]);
    DB::table('ipphone')->insert([
        ['id' => 'phone-1', 'cluster' => 'vqcwd4', 'pkey' => '1000'],
        ['id' => 'phone-2', 'cluster' => 'vqcwd4', 'pkey' => '1001'],
        ['id' => 'phone-other', 'cluster' => 'other01', 'pkey' => '2000'],
    ]);
    DB::table('inroutes')->insert([
        'id' => 'route-1',
        'cluster' => 'vqcwd4',
        'pkey' => 'main',
    ]);

    $tenant = (object) [
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
        'fqdn' => 'vqcwd4.pbx3.com',
    ];

    $counts = (new TenantMobilityService)->countTenantData($tenant);

    expect($counts['tenant']['shortuid'])->toBe('vqcwd4')
        ->and($counts['tables']['ipphone'])->toBe(2)
        ->and($counts['tables']['inroutes'])->toBe(1)
        ->and($counts['total_rows'])->toBe(3)
        ->and($counts['cluster'])->toBe(1)
        ->and(DB::table('cluster')->where('id', 'cluster-ksuid-1')->exists())->toBeTrue()
        ->and(DB::table('ipphone')->where('cluster', 'vqcwd4')->count())->toBe(2);
});

test('countTenantData rejects default tenant', function () {
    $tenant = (object) [
        'id' => 'default-id',
        'shortuid' => 'default',
        'pkey' => 'default',
    ];

    expect(fn () => (new TenantMobilityService)->countTenantData($tenant))
        ->toThrow(InvalidArgumentException::class, 'Cannot delete default tenant');
});

test('destroyTenantData prunes sibling dialaliases targeting the tenant', function () {
    Schema::create('dialalias', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster');
        $table->string('pkey');
        $table->string('target_fqdn')->nullable();
        $table->string('target_cluster')->nullable();
    });

    DB::table('cluster')->insert([
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
        'fqdn' => 'vqcwd4.pbx3.com',
    ]);
    DB::table('dialalias')->insert([
        [
            'id' => 'da-peer',
            'cluster' => 'peer01',
            'pkey' => '81',
            'target_fqdn' => 'vqcwd4.pbx3.com',
            'target_cluster' => 'vqcwd4',
        ],
        [
            'id' => 'da-other',
            'cluster' => 'peer01',
            'pkey' => '82',
            'target_fqdn' => 'other.pbx3.com',
            'target_cluster' => 'other01',
        ],
        [
            'id' => 'da-own',
            'cluster' => 'vqcwd4',
            'pkey' => '83',
            'target_fqdn' => 'peer01.pbx3.com',
            'target_cluster' => 'peer01',
        ],
    ]);

    $tenant = (object) [
        'id' => 'cluster-ksuid-1',
        'shortuid' => 'vqcwd4',
        'pkey' => 'sandycroft',
        'fqdn' => 'vqcwd4.pbx3.com',
    ];

    (new TenantMobilityService)->destroyTenantData($tenant);

    expect(DB::table('dialalias')->where('id', 'da-peer')->exists())->toBeFalse()
        ->and(DB::table('dialalias')->where('id', 'da-other')->exists())->toBeTrue()
        ->and(DB::table('dialalias')->where('id', 'da-own')->exists())->toBeFalse();

    Schema::dropIfExists('dialalias');
});

test('destroyTenantData rejects default tenant', function () {
    $tenant = (object) [
        'id' => 'default-id',
        'shortuid' => 'default',
        'pkey' => 'default',
    ];

    expect(fn () => (new TenantMobilityService)->destroyTenantData($tenant))
        ->toThrow(InvalidArgumentException::class, 'Cannot delete default tenant');
});
