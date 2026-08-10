<?php

uses(Tests\TestCase::class);

use App\Services\Tenant\TenantWipeIntegrityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @var string|null */
$dbPath = null;

beforeEach(function () use (&$dbPath) {
    $dbPath = tempnam(sys_get_temp_dir(), 'pbx3wipeint');
    if ($dbPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $dbPath]);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->unique();
        $table->string('pkey');
    });
    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster');
        $table->string('pkey')->nullable();
    });
});

afterEach(function () use (&$dbPath) {
    Schema::dropIfExists('ipphone');
    Schema::dropIfExists('cluster');
    DB::disconnect('sqlite');
    if (is_string($dbPath) && is_file($dbPath)) {
        @unlink($dbPath);
    }
    $dbPath = null;
});

test('T5 wipe list covers every cluster-scoped table in tenant schema SQL', function () {
    $candidates = [
        dirname(base_path()).'/pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql',
        base_path('../pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql'),
    ];
    $schema = null;
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $schema = $path;
            break;
        }
    }
    expect($schema)->not->toBeNull('sqlite_create_tenant.sql not found relative to pbx3api');

    $result = (new TenantWipeIntegrityService)->compareWipeListToSchema($schema);

    expect($result['ok'])->toBeTrue()
        ->and($result['missing_from_wipe_list'])->toBe([])
        ->and($result['schema_tables'])->not->toBeEmpty();
});

test('T4 orphan audit finds rows whose cluster is not a live tenant id', function () {
    DB::table('cluster')->insert([
        'id' => 'live-id',
        'shortuid' => 'live01',
        'pkey' => 'LiveSite',
    ]);
    DB::table('ipphone')->insert([
        ['id' => 'p1', 'cluster' => 'live01', 'pkey' => '1000'],
        ['id' => 'p2', 'cluster' => 'ghost1', 'pkey' => '2000'],
    ]);

    $result = (new TenantWipeIntegrityService)->auditOrphanClusterRows();

    expect($result['ok'])->toBeFalse()
        ->and($result['total'])->toBe(1)
        ->and($result['by_table']['ipphone'] ?? 0)->toBe(1);
});

test('T4 orphan audit is clean when all cluster values resolve', function () {
    DB::table('cluster')->insert([
        'id' => 'live-id',
        'shortuid' => 'live01',
        'pkey' => 'LiveSite',
    ]);
    DB::table('ipphone')->insert([
        ['id' => 'p1', 'cluster' => 'live01', 'pkey' => '1000'],
        ['id' => 'p2', 'cluster' => 'live-id', 'pkey' => '1001'],
        ['id' => 'p3', 'cluster' => 'LiveSite', 'pkey' => '1002'],
    ]);

    $result = (new TenantWipeIntegrityService)->auditOrphanClusterRows();

    expect($result['ok'])->toBeTrue()->and($result['total'])->toBe(0);
});
