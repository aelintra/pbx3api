<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrCsvImportService;
use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrQuery;

/** Golden-shaped Master.csv rows (no header). */
function pbx3GoldenCsvSample(): string
{
    return <<<'CSV'
"dhbm8x","+447908971810","Q1000","dhbm8x",""""" <+447908971810>","Local/Q1000@dhbm8x-00000009;2","PJSIP/fkdd5d-0000008a","Dial","PJSIP/fkdd5d/sip:fkdd5d@dhbm8x.pbx3.com","2026-07-24 11:44:19",,"2026-07-24 11:44:23",4,0,"NO ANSWER","DOCUMENTATION","1784893459.324",""
"dhbm8x","1001","009001234567","from-internal","""1001"" <1001>","PJSIP/aaaa1111-00000001","","Dial","PJSIP/Egress","2026-07-24 17:50:01","2026-07-24 17:50:02","2026-07-24 17:50:10",9,8,"ANSWERED","DOCUMENTATION","1784910000.1",""
"dhbm8x","1001","009001234568","from-internal","""1001"" <1001>","PJSIP/aaaa1111-00000002","","Dial","PJSIP/Egress","2026-07-24 17:50:15","2026-07-24 17:50:16","2026-07-24 17:50:20",5,4,"ANSWERED","DOCUMENTATION","1784910000.2",""
CSV;
}

test('CdrCsvImportService mapRow parses golden Master.csv dialect', function () {
    $svc = new CdrCsvImportService(new CdrIndexService, new CdrFixtureService(new CdrIndexService));
    $line = '"dhbm8x","+447908971810","Q1000","dhbm8x",""""" <+447908971810>","Local/Q1000@dhbm8x-00000009;2","PJSIP/fkdd5d-0000008a","Dial","PJSIP/fkdd5d/sip:fkdd5d@dhbm8x.pbx3.com","2026-07-24 11:44:19",,"2026-07-24 11:44:23",4,0,"NO ANSWER","DOCUMENTATION","1784893459.324",""';
    $row = str_getcsv($line);
    $mapped = $svc->mapRow($row);
    expect($mapped)->not->toBeNull()
        ->and($mapped['accountcode'])->toBe('dhbm8x')
        ->and($mapped['src'])->toBe('+447908971810')
        ->and($mapped['dst'])->toBe('Q1000')
        ->and($mapped['calldate'])->toBe('2026-07-24 11:44:19')
        ->and($mapped['duration'])->toBe(4)
        ->and($mapped['billsec'])->toBe(0)
        ->and($mapped['disposition'])->toBe('NO ANSWER')
        ->and($mapped['amaflags'])->toBe(3)
        ->and($mapped['uniqueid'])->toBe('1784893459.324');
});

test('CdrCsvImportService imports CSV into lab sqlite and velocity can query', function () {
    $csv = sys_get_temp_dir().'/pbx3-master-'.bin2hex(random_bytes(4)).'.csv';
    $db = sys_get_temp_dir().'/pbx3-cdr-import-'.bin2hex(random_bytes(4)).'.db';
    file_put_contents($csv, pbx3GoldenCsvSample());

    config([
        'pbx3_cdr.sqlite_path' => $db,
        'pbx3_ops.velocity_window_minutes' => 100000, // include dated sample rows regardless of "now"
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
    ]);

    $importer = new CdrCsvImportService(new CdrIndexService, new CdrFixtureService(new CdrIndexService));
    $result = $importer->import([
        'file' => $csv,
        'path' => $db,
        'force' => true,
    ]);
    expect($result['inserted'])->toBe(3)
        ->and($result['skipped'])->toBe(0);

    $all = (new CdrIndexService)->list(['limit' => 50]);
    expect($all['total'])->toBe(3);

    // Make premium rows "recent" for default window by rewriting calldates
    $pdo = new PDO('sqlite:'.$db);
    $pdo->exec("UPDATE cdr SET calldate = datetime('now') WHERE dst LIKE '00900%'");

    $probe = (new VelocityCdrQuery(new CdrIndexService))->candidates(5);
    expect($probe['total'])->toBe(2)
        ->and($probe['by_src']['1001'] ?? 0)->toBe(2);

    @unlink($csv);
    @unlink($db);
});

test('CdrCsvImportService refuses live master.db without allow-live', function () {
    $csv = sys_get_temp_dir().'/pbx3-master-'.bin2hex(random_bytes(4)).'.csv';
    file_put_contents($csv, pbx3GoldenCsvSample());
    $svc = new CdrCsvImportService(new CdrIndexService, new CdrFixtureService(new CdrIndexService));
    expect(fn () => $svc->import([
        'file' => $csv,
        'path' => CdrFixtureService::LIVE_DEFAULT_PATH,
        'force' => true,
    ]))->toThrow(RuntimeException::class, 'Refusing to write live');
    @unlink($csv);
});
