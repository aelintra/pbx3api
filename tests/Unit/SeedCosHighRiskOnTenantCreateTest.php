<?php

uses(Tests\TestCase::class);

use App\Models\ClassOfService;
use App\Models\Tenant;
use App\Services\Tenant\SeedCosHighRiskOnTenantCreate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @var string|null */
$dbPath = null;

beforeEach(function () use (&$dbPath) {
    $dbPath = tempnam(sys_get_temp_dir(), 'pbx3coshr');
    if ($dbPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $dbPath]);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cos', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster');
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->text('dialplan')->nullable();
        $table->string('active')->default('YES');
        $table->string('defaultopen')->default('NO');
        $table->string('defaultclosed')->default('NO');
        $table->string('orideopen')->default('NO');
        $table->string('orideclosed')->default('NO');
        $table->string('z_updater')->nullable();
    });
});

afterEach(function () use (&$dbPath) {
    if ($dbPath !== null && is_file($dbPath)) {
        @unlink($dbPath);
    }
});

test('UK pack seeds HR_UK070 and HR_OFFSHORE with defaults', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenanta1';
    $tenant->pkey = 'TenantA';

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk');

    expect($rows)->toHaveCount(2);
    $byPkey = collect($rows)->keyBy('pkey');
    expect($byPkey->has(SeedCosHighRiskOnTenantCreate::PKEY_UK070))->toBeTrue()
        ->and($byPkey->has(SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE))->toBeTrue();

    $uk070 = $byPkey->get(SeedCosHighRiskOnTenantCreate::PKEY_UK070);
    expect($uk070->defaultopen)->toBe('YES')
        ->and($uk070->defaultclosed)->toBe('YES')
        ->and($uk070->dialplan)->toContain('_070.')
        ->and($uk070->dialplan)->toContain('_+4470.')
        ->and($uk070->dialplan)->not->toContain('_071.');

    $off = $byPkey->get(SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE);
    expect($off->dialplan)->toContain('_001268.')
        ->and($off->dialplan)->toContain('_+1268.')
        ->and($off->dialplan)->toContain('_00252.');

    // Idempotent without --force
    expect(app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk'))->toHaveCount(0);
    expect(ClassOfService::where('cluster', 'tenanta1')->count())->toBe(2);
});

test('US pack seeds single HR_OFFSHORE with 1NPA forms', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenantb1';
    $tenant->pkey = 'TenantB';

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'us');

    expect($rows)->toHaveCount(1);
    $off = $rows[0];
    expect($off->pkey)->toBe(SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE)
        ->and($off->dialplan)->toContain('_1268.')
        ->and($off->dialplan)->toContain('_011252.')
        ->and($off->dialplan)->toContain('_070.');
});

test('force refreshes dialplan on existing rule', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenantc1';
    $tenant->pkey = 'TenantC';

    app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk');
    ClassOfService::where('cluster', 'tenantc1')
        ->where('pkey', SeedCosHighRiskOnTenantCreate::PKEY_UK070)
        ->update(['dialplan' => '_999.']);

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk', true);
    expect($rows)->not->toBeEmpty();
    $uk070 = ClassOfService::where('cluster', 'tenantc1')
        ->where('pkey', SeedCosHighRiskOnTenantCreate::PKEY_UK070)
        ->first();
    expect($uk070->dialplan)->toContain('_070.')
        ->and($uk070->dialplan)->not->toBe('_999.');
});
