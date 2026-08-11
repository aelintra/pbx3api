<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrPack;
use App\Services\Cdr\VelocityCdrQuery;

test('VelocityCdrPack all cases pass', function () {
    $pack = new VelocityCdrPack(
        new CdrFixtureService(new CdrIndexService),
        new VelocityCdrQuery(new CdrIndexService),
    );
    $result = $pack->run();
    expect($result['failed'])->toBe(0)
        ->and($result['passed'])->toBe(count($result['cases']))
        ->and($result['passed'])->toBeGreaterThanOrEqual(6);
});
