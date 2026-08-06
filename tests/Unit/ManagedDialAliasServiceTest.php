<?php

uses(Tests\TestCase::class);

use App\Models\DialAlias;
use App\Services\Fleet\ManagedDialAliasService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @var string|null */
$dbPath = null;

beforeEach(function () use (&$dbPath) {
    $dbPath = tempnam(sys_get_temp_dir(), 'pbx3dialc');
    if ($dbPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $dbPath]);
    config(['database.connections.sqlite.prefix' => '']);
    config(['pbx3_fleet.dial_cohort' => false]);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->unique();
        $table->string('pkey');
        $table->string('fqdn')->nullable();
    });
    Schema::create('dialalias', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('active')->nullable();
        $table->string('cluster');
        $table->string('target_cluster')->nullable();
        $table->string('target_fqdn');
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->string('source')->nullable();
        $table->string('cohort_id')->nullable();
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    DB::table('cluster')->insert([
        [
            'id' => 'ksuidtenantaaaaaaaaaaaaaa1',
            'shortuid' => '9wvvnb',
            'pkey' => 'affcot',
            'fqdn' => '9wvvnb.pbx3.com',
        ],
        [
            'id' => 'ksuidtenantbbbbbbbbbbbbbb2',
            'shortuid' => 'dhbm8x',
            'pkey' => 'duns',
            'fqdn' => 'dhbm8x.pbx3.com',
        ],
    ]);
});

afterEach(function () use (&$dbPath) {
    if ($dbPath !== null && is_file($dbPath)) {
        @unlink($dbPath);
    }
});

test('normalizeTenantFqdn accepts host and rejects bare shortuid', function () {
    $svc = app(ManagedDialAliasService::class);
    expect($svc->normalizeTenantFqdn('dhbm8x.pbx3.com'))->toBe('dhbm8x.pbx3.com');
    expect($svc->normalizeTenantFqdn('https://DHBM8X.PBX3.COM:5060/path'))->toBe('dhbm8x.pbx3.com');
    expect($svc->normalizeTenantFqdn('dhbm8x'))->toBeNull();
});

test('isManaged detects source=cohort', function () {
    $row = new DialAlias(['source' => 'cohort']);
    expect(ManagedDialAliasService::isManaged($row))->toBeTrue();
    expect(ManagedDialAliasService::isManaged(new DialAlias(['source' => 'manual'])))->toBeFalse();
});

test('upsert creates managed dialalias', function () {
    $svc = app(ManagedDialAliasService::class);
    $result = $svc->upsert([
        'cluster' => '9wvvnb',
        'pkey' => '82',
        'target_fqdn' => 'dhbm8x.pbx3.com',
        'target_cluster' => 'dhbm8x',
        'cohort_id' => 'dc_test1',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['action'])->toBe('created');
    expect($result['dialalias']->source)->toBe('cohort');
    expect($result['dialalias']->cohort_id)->toBe('dc_test1');
    expect($result['dialalias']->pkey)->toBe('82');
    expect(DialAlias::where('cluster', '9wvvnb')->where('pkey', '82')->count())->toBe(1);
});

test('upsert converts manual row to managed', function () {
    DB::table('dialalias')->insert([
        'id' => 'ksuiddialaliasaaaaaaaaaa01',
        'shortuid' => 'abcd12',
        'pkey' => '82',
        'active' => 'YES',
        'cluster' => '9wvvnb',
        'target_fqdn' => 'dhbm8x.pbx3.com',
        'source' => 'manual',
    ]);

    $svc = app(ManagedDialAliasService::class);
    $result = $svc->upsert([
        'cluster' => '9wvvnb',
        'pkey' => '82',
        'target_fqdn' => 'dhbm8x.pbx3.com',
        'cohort_id' => 'dc_test2',
    ]);

    expect($result['action'])->toBe('updated');
    $row = DialAlias::where('cluster', '9wvvnb')->where('pkey', '82')->first();
    expect($row->source)->toBe('cohort');
    expect($row->cohort_id)->toBe('dc_test2');
});

test('delete managed_only refuses manual rows', function () {
    DB::table('dialalias')->insert([
        'id' => 'ksuiddialaliasaaaaaaaaaa02',
        'shortuid' => 'efgh34',
        'pkey' => '81',
        'active' => 'YES',
        'cluster' => '9wvvnb',
        'target_fqdn' => 'dhbm8x.pbx3.com',
        'source' => 'manual',
    ]);

    $svc = app(ManagedDialAliasService::class);
    expect(fn () => $svc->delete([
        'cluster' => '9wvvnb',
        'pkey' => '81',
    ]))->toThrow(RuntimeException::class);
});

test('delete with managed_only false prunes manual', function () {
    DB::table('dialalias')->insert([
        'id' => 'ksuiddialaliasaaaaaaaaaa03',
        'shortuid' => 'ijkl56',
        'pkey' => '81',
        'active' => 'YES',
        'cluster' => '9wvvnb',
        'target_fqdn' => 'dhbm8x.pbx3.com',
        'source' => 'manual',
    ]);

    $svc = app(ManagedDialAliasService::class);
    $result = $svc->delete([
        'cluster' => '9wvvnb',
        'pkey' => '81',
        'managed_only' => false,
    ]);

    expect($result['ok'])->toBeTrue();
    expect(DialAlias::where('pkey', '81')->count())->toBe(0);
});

test('cohortFeatureOn reads config', function () {
    config(['pbx3_fleet.dial_cohort' => true]);
    expect(ManagedDialAliasService::cohortFeatureOn())->toBeTrue();
    config(['pbx3_fleet.dial_cohort' => false]);
    expect(ManagedDialAliasService::cohortFeatureOn())->toBeFalse();
});
