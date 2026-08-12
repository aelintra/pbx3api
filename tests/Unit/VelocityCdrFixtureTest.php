<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrQuery;

function pbx3VelocityTempDb(): string
{
    return sys_get_temp_dir().'/pbx3-velocity-'.bin2hex(random_bytes(4)).'.db';
}

test('CdrFixtureService refuses live master.db without allow-live', function () {
    $svc = new CdrFixtureService(new CdrIndexService);
    expect(fn () => $svc->seed([
        'path' => CdrFixtureService::LIVE_DEFAULT_PATH,
        'force' => true,
    ]))->toThrow(RuntimeException::class, 'Refusing to write live');
});

test('CdrFixtureService IRSF deck + VelocityCdrQuery returns burst by src', function () {
    $path = pbx3VelocityTempDb();
    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_window_minutes' => 5,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
    ]);

    $fixture = new CdrFixtureService(new CdrIndexService);
    $seeded = $fixture->seed([
        'path' => $path,
        'deck' => 'irsf',
        'count' => 12,
        'src' => '1001',
        'accountcode' => 'labtenant',
        'force' => true,
    ]);
    expect($seeded['inserted'])->toBe(12)
        ->and($seeded['created'])->toBeTrue();

    $query = new VelocityCdrQuery(new CdrIndexService);
    $result = $query->candidates();
    expect($result['available'])->toBeTrue()
        ->and($result['total'])->toBe(12)
        ->and($result['by_src']['1001'] ?? 0)->toBe(12)
        ->and($result['rows'][0]['dst'])->toStartWith(CdrFixtureService::LAB_PREMIUM_PREFIX)
        ->and($result['rows'][0]['accountcode'])->toBe('labtenant');

    @unlink($path);
});

test('VelocityCdrQuery excludes internal-noise deck destinations', function () {
    $path = pbx3VelocityTempDb();
    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_window_minutes' => 5,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
    ]);

    $fixture = new CdrFixtureService(new CdrIndexService);
    $fixture->seed([
        'path' => $path,
        'deck' => 'mixed',
        'count' => 10,
        'src' => '1001',
        'force' => true,
    ]);

    $query = new VelocityCdrQuery(new CdrIndexService);
    $result = $query->candidates();
    // mixed ≈ 60% irsf + 40% internal — only premium dst rows remain
    expect($result['total'])->toBeGreaterThanOrEqual(5)
        ->and($result['total'])->toBeLessThan(10);

    foreach ($result['rows'] as $row) {
        expect($row['dst'])->toStartWith(CdrFixtureService::LAB_PREMIUM_PREFIX);
    }

    @unlink($path);
});

test('VelocityCdrQuery isInternalDst heuristics', function () {
    $q = new VelocityCdrQuery(new CdrIndexService);
    expect($q->isInternalDst('1002'))->toBeTrue()
        ->and($q->isInternalDst('vqcwd4'))->toBeTrue()
        ->and($q->isInternalDst('09001234567'))->toBeFalse()
        ->and($q->isInternalDst('+441924918076'))->toBeFalse();
});
