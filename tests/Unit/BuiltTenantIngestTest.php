<?php

uses(Tests\TestCase::class);

use App\Services\Tenant\BuiltTenantIngestService;
use Illuminate\Support\Facades\DB;

// Private offline-migrate worktree on ops Mac; set PBX3_INGEST_FIXTURE_DB to run this suite.
$flixtonSrc = getenv('PBX3_INGEST_FIXTURE_DB') ?: '';

/** @var string|null */
$homeDb = null;
/** @var string|null */
$sourceCopy = null;

beforeEach(function () use (&$homeDb, &$sourceCopy, $flixtonSrc) {
    if (! is_file($flixtonSrc)) {
        $this->markTestSkipped('flixton.db smoke artifact missing: '.$flixtonSrc);
    }

    $idpwgen = dirname(__DIR__, 2).'/../pbx3/pbx3-1/opt/pbx3/golang/idpwgen';
    if (is_executable($idpwgen)) {
        putenv('IDPWGEN_PATH='.$idpwgen);
        $_ENV['IDPWGEN_PATH'] = $idpwgen;
    }

    $homeDb = tempnam(sys_get_temp_dir(), 'pbx3home');
    $sourceCopy = tempnam(sys_get_temp_dir(), 'pbx3src');
    if ($homeDb === false || $sourceCopy === false) {
        throw new RuntimeException('tempnam failed');
    }
    copy($flixtonSrc, $homeDb);
    copy($flixtonSrc, $sourceCopy);

    // Home = empty of tenants but keep schema + instance globals/trunks posture.
    $pdo = new PDO('sqlite:'.$homeDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('DELETE FROM cluster');
    foreach ([
        'agent', 'appl', 'cos', 'cos_profile', 'cos_profile_open', 'cos_profile_closed',
        'dateseg', 'greeting', 'holiday', 'inroutes', 'ipphone', 'ipphonecosopen',
        'ipphonecosclosed', 'ivrmenu', 'page', 'meetme', 'queue', 'recordings',
        'route', 'dialalias', 'route_profile', 'route_profile_line',
    ] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec("UPDATE globals SET domain = 'pbx3.com', id = 'home-instance-ksuid' WHERE rowid = 1");
    // Ensure an Egress trunk so FleetPostureService treats node as fleet.
    $hasEgress = (int) $pdo->query("SELECT COUNT(*) FROM trunks WHERE pkey = 'Egress'")->fetchColumn();
    if ($hasEgress < 1) {
        $cols = $pdo->query('PRAGMA table_info(trunks)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $row = [];
        foreach ($names as $n) {
            $row[$n] = null;
        }
        if (in_array('pkey', $names, true)) {
            $row['pkey'] = 'Egress';
        }
        if (in_array('active', $names, true)) {
            $row['active'] = 'YES';
        }
        if (in_array('id', $names, true)) {
            $row['id'] = 'egress-trunk-id';
        }
        $colList = implode(',', array_map(fn ($c) => '"'.$c.'"', array_keys($row)));
        $placeholders = implode(',', array_fill(0, count($row), '?'));
        $pdo->prepare("INSERT INTO trunks ({$colList}) VALUES ({$placeholders})")
            ->execute(array_values($row));
    } else {
        $pdo->exec("UPDATE trunks SET active = 'YES' WHERE pkey = 'Egress'");
    }
    $pdo = null;

    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => $homeDb]);
    config(['database.connections.sqlite.prefix' => '']);
    config(['pbx3_fleet.mode' => true]);
    config(['pbx3_fleet.egress_trunk_pkey' => 'Egress']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');
});

afterEach(function () use (&$homeDb, &$sourceCopy) {
    DB::disconnect('sqlite');
    if (is_string($homeDb) && is_file($homeDb)) {
        @unlink($homeDb);
    }
    if (is_string($sourceCopy) && is_file($sourceCopy)) {
        @unlink($sourceCopy);
    }
    $homeDb = null;
    $sourceCopy = null;
});

test('flixton dry-run plans preserve shortuid and FQDN', function () use (&$sourceCopy) {
    $plan = (new BuiltTenantIngestService)->ingest($sourceCopy, ['dry_run' => true]);

    expect($plan['dry_run'])->toBeTrue()
        ->and($plan['applied'])->toBeFalse()
        ->and($plan['blocking_errors'])->toBe([])
        ->and($plan['source']['pkey'])->toBe('flixton')
        ->and($plan['source']['shortuid'])->toBe('37yzp1')
        ->and($plan['planned']['shortuid'])->toBe('37yzp1')
        ->and($plan['planned']['remint_shortuid'])->toBeFalse()
        ->and($plan['planned']['fqdn'])->toBe('37yzp1.pbx3.com')
        ->and($plan['row_counts']['ipphone'])->toBe(2)
        ->and($plan['row_counts']['route'])->toBe(3)
        ->and(DB::table('cluster')->count())->toBe(0);
});

test('flixton apply merges tenant and normalizes routes to Egress', function () use (&$sourceCopy) {
    $result = (new BuiltTenantIngestService)->ingest($sourceCopy, ['dry_run' => false]);

    expect($result['applied'])->toBeTrue()
        ->and(DB::table('cluster')->where('pkey', 'flixton')->exists())->toBeTrue();

    $cluster = DB::table('cluster')->where('pkey', 'flixton')->first();
    expect($cluster->shortuid)->toBe('37yzp1')
        ->and($cluster->fqdn)->toBe('37yzp1.pbx3.com')
        ->and(DB::table('ipphone')->where('cluster', '37yzp1')->count())->toBe(2)
        ->and(DB::table('route')->where('cluster', '37yzp1')->count())->toBe(3)
        ->and(DB::table('trunks')->where('pkey', '!=', 'Egress')->count())->toBeGreaterThan(0); // source trunks stay only if we didn't wipe — home still has ETL trunks from copy!

    // Home was copied from flixton so it still has inactive ETL trunks — ingest must not add cluster-scoped junk via trunks merge.
    // Routes must point at Egress.
    $paths = DB::table('route')->where('cluster', '37yzp1')->pluck('path1')->unique()->all();
    expect($paths)->toBe(['Egress']);
});

test('shortuid collision remints opaque key but keeps pkey', function () use (&$sourceCopy) {
    DB::table('cluster')->insert([
        'id' => 'existing-other-id',
        'shortuid' => '37yzp1',
        'pkey' => 'OtherTenant',
        'fqdn' => '37yzp1.pbx3.com',
    ]);

    $result = (new BuiltTenantIngestService)->ingest($sourceCopy, ['dry_run' => false]);

    expect($result['planned']['remint_shortuid'])->toBeTrue()
        ->and($result['planned']['pkey'])->toBe('flixton')
        ->and($result['planned']['shortuid'])->not->toBe('37yzp1');

    $flixton = DB::table('cluster')->where('pkey', 'flixton')->first();
    expect($flixton->shortuid)->toBe($result['planned']['shortuid'])
        ->and($flixton->fqdn)->toBe($result['planned']['shortuid'].'.pbx3.com')
        ->and(DB::table('ipphone')->where('cluster', $flixton->shortuid)->count())->toBe(2);
});

test('pkey collision blocks ingest', function () use (&$sourceCopy) {
    DB::table('cluster')->insert([
        'id' => 'existing-flixton-id',
        'shortuid' => 'aaaaaa',
        'pkey' => 'flixton',
        'fqdn' => 'aaaaaa.pbx3.com',
    ]);

    expect(fn () => (new BuiltTenantIngestService)->ingest($sourceCopy, ['dry_run' => false]))
        ->toThrow(RuntimeException::class, 'pkey collision');
});
