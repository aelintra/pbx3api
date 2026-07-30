<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\CdrRetentionService;
use App\Services\Directory\LogRetentionService;

function pbx3MakeCdrDb(string $path): void
{
    $pdo = new \PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE cdr (
        calldate TEXT, clid TEXT, src TEXT, dst TEXT, dcontext TEXT,
        channel TEXT, dstchannel TEXT, lastapp TEXT, lastdata TEXT,
        duration INTEGER, billsec INTEGER, disposition TEXT, amaflags INTEGER,
        accountcode TEXT, uniqueid TEXT, userfield TEXT, linkedid TEXT,
        peeraccount TEXT, sequence INTEGER
    )');
    $ins = $pdo->prepare('INSERT INTO cdr (calldate, clid, src, dst, duration, billsec, disposition, accountcode, uniqueid)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(['2026-07-10 12:00:00', '"Alice" <100>', '100', '200', 30, 25, 'ANSWERED', 'default', 'uid-old']);
    $ins->execute(['2026-07-16 15:30:00', '"Bob" <101>', '101', '911', 10, 10, 'ANSWERED', 'tenant1', 'uid-new']);
    $ins->execute(['2026-07-16 16:00:00', '"Carol" <102>', '102', '200', 5, 0, 'NO ANSWER', 'default', 'uid-na']);
}

test('CdrIndexService lists and filters by search', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-'.bin2hex(random_bytes(4)).'.db';
    pbx3MakeCdrDb($path);
    config(['pbx3_cdr.sqlite_path' => $path]);

    $svc = new CdrIndexService;
    $all = $svc->list(['limit' => 50]);
    expect($all['available'])->toBeTrue()
        ->and($all['total'])->toBe(3);

    $search = $svc->list(['search' => '911']);
    expect($search['total'])->toBe(1)
        ->and($search['rows'][0]['dst'])->toBe('911');

    $acct = $svc->list(['accountcode' => 'tenant1']);
    expect($acct['total'])->toBe(1);

    @unlink($path);
});

test('CdrIndexService volumeLast24h and outcomeToday aggregate dispositions', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-agg-'.bin2hex(random_bytes(4)).'.db';
    $pdo = new \PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE cdr (
        calldate TEXT, clid TEXT, src TEXT, dst TEXT, dcontext TEXT,
        channel TEXT, dstchannel TEXT, lastapp TEXT, lastdata TEXT,
        duration INTEGER, billsec INTEGER, disposition TEXT, amaflags INTEGER,
        accountcode TEXT, uniqueid TEXT, userfield TEXT, linkedid TEXT
    )');
    $ins = $pdo->prepare('INSERT INTO cdr (calldate, disposition, accountcode, uniqueid)
        VALUES (?, ?, ?, ?)');

    $now = new \DateTimeImmutable('now');
    $hour = $now->setTime((int) $now->format('H'), 0, 0);
    $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
    $ins->execute([$hour->format('Y-m-d H:i:s'), 'ANSWERED', 'default', 'u1']);
    $ins->execute([$hour->format('Y-m-d H:i:s'), 'NO ANSWER', 'default', 'u2']);
    $ins->execute([$hour->format('Y-m-d H:i:s'), 'BUSY', 'tenant1', 'u3']);
    $ins->execute([$today, 'FAILED', 'default', 'u4']);
    // Outside 24h window
    $ins->execute([$hour->modify('-30 hours')->format('Y-m-d H:i:s'), 'ANSWERED', 'default', 'u-old']);

    config(['pbx3_cdr.sqlite_path' => $path]);
    $svc = new CdrIndexService;

    $vol = $svc->volumeLast24h();
    expect($vol['available'])->toBeTrue()
        ->and(count($vol['labels']))->toBe(24)
        ->and(array_sum($vol['answered']))->toBe(1)
        ->and(array_sum($vol['other']))->toBe(3);

    $scoped = $svc->volumeLast24h(['accountcode' => 'tenant1']);
    expect(array_sum($scoped['answered']))->toBe(0)
        ->and(array_sum($scoped['other']))->toBe(1);

    $out = $svc->outcomeToday();
    expect($out['available'])->toBeTrue()
        ->and($out['answered'])->toBe(1)
        ->and($out['no_answer'])->toBe(1)
        ->and($out['busy'])->toBe(1)
        ->and($out['failed'])->toBe(1);

    @unlink($path);
});

test('HomePulseService parseInclude defaults and filters', function () {
    expect(\App\Services\HomePulseService::parseInclude(null))
        ->toBe(['system', 'live', 'cdr'])
        ->and(\App\Services\HomePulseService::parseInclude('cdr,live'))
        ->toBe(['cdr', 'live'])
        ->and(\App\Services\HomePulseService::parseInclude('bogus'))
        ->toBe(['system', 'live', 'cdr']);
});

test('HomePulseService pulse respects include and returns system without AMI', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-pulse-'.bin2hex(random_bytes(4)).'.db';
    // Missing file → cdr available false
    config(['pbx3_cdr.sqlite_path' => $path]);

    $svc = new \App\Services\HomePulseService(new CdrIndexService);
    $onlyCdr = $svc->pulse(['cdr']);
    expect($onlyCdr)->toHaveKey('cdr')
        ->and($onlyCdr)->not->toHaveKey('system')
        ->and($onlyCdr)->not->toHaveKey('live')
        ->and($onlyCdr['cdr']['available'])->toBeFalse();

    $sys = $svc->pulse(['system']);
    expect($sys)->toHaveKey('system')
        ->and($sys['system'])->toHaveKeys(['load1', 'load5', 'load15', 'cpus', 'pbx_runstate']);
});

test('CdrRetentionService prunes old rows', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-'.bin2hex(random_bytes(4)).'.db';
    pbx3MakeCdrDb($path);
    config(['pbx3_cdr.sqlite_path' => $path]);
    config([
        'pbx3_logs.retention_override_path' => sys_get_temp_dir().'/pbx3-ret-missing.json',
        'pbx3_logs.local_days' => ['syslog' => 7, 'asterisk-messages' => 7, 'cdr' => 7],
        'pbx3_logs.s3_maxage_days' => ['syslog' => 30, 'asterisk-messages' => 30, 'cdr' => 60],
        'pbx3_directory.org_bucket' => '',
    ]);

    $ret = new CdrRetentionService(new CdrIndexService, new LogRetentionService);
    // Force short retention relative to 2026-07-10 row (test runs ~2026-07-17)
    $stats = $ret->prune(5);
    expect($stats['deleted'])->toBeGreaterThanOrEqual(1);

    $left = (new CdrIndexService)->list();
    expect($left['total'])->toBeLessThan(3);

    @unlink($path);
});
