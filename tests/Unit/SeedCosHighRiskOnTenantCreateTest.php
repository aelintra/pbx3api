<?php

uses(Tests\TestCase::class);

use App\Models\ClassOfService;
use App\Models\CosProfile;
use App\Models\CosProfileClosed;
use App\Models\CosProfileOpen;
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

    Schema::create('cos_profile', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster');
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->string('active')->default('YES');
        $table->string('is_default')->default('NO');
        $table->string('z_updater')->nullable();
    });

    Schema::create('cos_profile_open', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster')->nullable();
        $table->string('active')->default('YES');
        $table->string('profile_pkey');
        $table->string('cos_pkey');
    });

    Schema::create('cos_profile_closed', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster')->nullable();
        $table->string('active')->default('YES');
        $table->string('profile_pkey');
        $table->string('cos_pkey');
    });
});

afterEach(function () use (&$dbPath) {
    if ($dbPath !== null && is_file($dbPath)) {
        @unlink($dbPath);
    }
});

test('UK pack seeds HR_* with Tenant-wide ON and default profile attach', function () {
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
        ->and($uk070->orideopen)->toBe('YES')
        ->and($uk070->orideclosed)->toBe('YES')
        ->and($uk070->dialplan)->toContain('_070.')
        ->and($uk070->dialplan)->toContain('_+4470.')
        ->and($uk070->dialplan)->not->toContain('_071.');

    $off = $byPkey->get(SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE);
    expect($off->orideopen)->toBe('YES')
        ->and($off->orideclosed)->toBe('YES')
        ->and($off->dialplan)->toContain('_001268.')
        ->and($off->dialplan)->toContain('_+1268.')
        ->and($off->dialplan)->toContain('_00252.');

    $default = CosProfile::query()
        ->where('cluster', 'tenanta1')
        ->whereRaw("upper(trim(is_default)) = 'YES'")
        ->first();
    expect($default)->not->toBeNull()
        ->and($default->cname)->toBe('Unrestricted');

    $open = CosProfileOpen::where('profile_pkey', $default->pkey)->pluck('cos_pkey')->sort()->values()->all();
    $closed = CosProfileClosed::where('profile_pkey', $default->pkey)->pluck('cos_pkey')->sort()->values()->all();
    expect($open)->toBe([
        SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE,
        SeedCosHighRiskOnTenantCreate::PKEY_UK070,
    ])->and($closed)->toBe($open);

    // Idempotent without --force
    expect(app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk'))->toHaveCount(0);
    expect(ClassOfService::where('cluster', 'tenanta1')->count())->toBe(2);
    expect(CosProfile::where('cluster', 'tenanta1')->whereRaw("upper(trim(is_default)) = 'YES'")->count())->toBe(1);
});

test('US pack seeds single HR_OFFSHORE with 1NPA forms and default profile', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenantb1';
    $tenant->pkey = 'TenantB';

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'us');

    expect($rows)->toHaveCount(1);
    $off = $rows[0];
    expect($off->pkey)->toBe(SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE)
        ->and($off->orideopen)->toBe('YES')
        ->and($off->orideclosed)->toBe('YES')
        ->and($off->dialplan)->toContain('_1268.')
        ->and($off->dialplan)->toContain('_011252.')
        ->and($off->dialplan)->toContain('_070.');

    $default = CosProfile::query()
        ->where('cluster', 'tenantb1')
        ->whereRaw("upper(trim(is_default)) = 'YES'")
        ->first();
    expect($default)->not->toBeNull();
    expect(CosProfileOpen::where('profile_pkey', $default->pkey)->pluck('cos_pkey')->all())
        ->toBe([SeedCosHighRiskOnTenantCreate::PKEY_OFFSHORE]);
});

test('force refreshes dialplan on existing rule and keeps floor ON', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenantc1';
    $tenant->pkey = 'TenantC';

    app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk');
    ClassOfService::where('cluster', 'tenantc1')
        ->where('pkey', SeedCosHighRiskOnTenantCreate::PKEY_UK070)
        ->update(['dialplan' => '_999.', 'orideopen' => 'NO', 'orideclosed' => 'NO']);

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk', true);
    expect($rows)->not->toBeEmpty();
    $uk070 = ClassOfService::where('cluster', 'tenantc1')
        ->where('pkey', SeedCosHighRiskOnTenantCreate::PKEY_UK070)
        ->first();
    expect($uk070->dialplan)->toContain('_070.')
        ->and($uk070->dialplan)->not->toBe('_999.')
        ->and($uk070->orideopen)->toBe('YES')
        ->and($uk070->orideclosed)->toBe('YES');
});

test('re-seed restores Tenant-wide ON without force when floor was cleared', function () {
    $tenant = new Tenant;
    $tenant->shortuid = 'tenantd1';
    $tenant->pkey = 'TenantD';

    app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk');
    ClassOfService::where('cluster', 'tenantd1')->update([
        'orideopen' => 'NO',
        'orideclosed' => 'NO',
    ]);

    $rows = app(SeedCosHighRiskOnTenantCreate::class)->seed($tenant, 'uk');
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->orideopen)->toBe('YES')->and($row->orideclosed)->toBe('YES');
    }
});
