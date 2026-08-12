<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrDestClassifier;
use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\CountryCallingCodes;

test('CountryCallingCodes longest-match prefers NANP overlay over 1', function () {
    expect(CountryCallingCodes::match('12685551212'))->toBe('1268')
        ->and(CountryCallingCodes::match('441924910444'))->toBe('44')
        ->and(CountryCallingCodes::match('15551234567'))->toBe('1');
});

test('CdrDestClassifier buckets internal high-cost international domestic', function () {
    config([
        'pbx3_cdr.home_country_code' => '44',
        'pbx3_ops.velocity_prefixes' => '0900,+44900,0044900',
    ]);
    $c = new CdrDestClassifier;

    expect($c->classify('1001'))->toBe('internal')
        ->and($c->classify('09001234567'))->toBe('high_cost')
        ->and($c->classify('+449001234567'))->toBe('high_cost')
        ->and($c->classify('0012685551212'))->toBe('international')
        ->and($c->classify('+12685551212'))->toBe('international')
        ->and($c->classify('+441924910444'))->toBe('domestic') // home CC via E.164
        ->and($c->classify('01924910444'))->toBe('domestic');
});

test('CdrIndexService destWhereToday aggregates dest classes', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-dest-'.bin2hex(random_bytes(4)).'.db';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE cdr (
        calldate TEXT, clid TEXT, src TEXT, dst TEXT, dcontext TEXT,
        channel TEXT, dstchannel TEXT, lastapp TEXT, lastdata TEXT,
        duration INTEGER, billsec INTEGER, disposition TEXT, amaflags INTEGER,
        accountcode TEXT, uniqueid TEXT, userfield TEXT, linkedid TEXT
    )');
    $ins = $pdo->prepare('INSERT INTO cdr (calldate, src, dst, disposition, accountcode, uniqueid)
        VALUES (?, ?, ?, ?, ?, ?)');
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $ins->execute([$today, '1001', '1002', 'ANSWERED', 't1', 'a']);
    $ins->execute([$today, '1001', '09001234567', 'ANSWERED', 't1', 'b']);
    $ins->execute([$today, '1001', '0012685551212', 'ANSWERED', 't1', 'c']);
    $ins->execute([$today, '1001', '01924910444', 'ANSWERED', 't1', 'd']);

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_cdr.site_timezone' => 'UTC',
        'pbx3_cdr.home_country_code' => '44',
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
    ]);

    $agg = (new CdrIndexService)->destWhereToday();
    expect($agg['available'])->toBeTrue()
        ->and($agg['internal'])->toBe(1)
        ->and($agg['high_cost'])->toBe(1)
        ->and($agg['international'])->toBe(1)
        ->and($agg['domestic'])->toBe(1)
        ->and($agg['total'])->toBe(4);

    @unlink($path);
});
