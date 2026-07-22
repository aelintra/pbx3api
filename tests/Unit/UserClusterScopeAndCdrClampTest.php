<?php

uses(Tests\TestCase::class);

use App\Models\User;
use App\Services\Cdr\CdrIndexService;
use App\Support\ClusterAccess;

function pbx3MakeCdrDbForClamp(string $path): void
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

test('User isAdminAbility and allowed clusters helpers', function () {
    $admin = new User([
        'abilities' => ['admin'],
        'allowed_clusters' => null,
        'portable' => false,
    ]);
    expect($admin->isAdminAbility())->toBeTrue()
        ->and($admin->allowedClusterShortuids())->toBe([])
        ->and($admin->portable)->toBeFalse();

    $tenant = new User([
        'abilities' => ['tenant', 'recordings'],
        'allowed_clusters' => ['abc12345'],
        'portable' => true,
    ]);
    expect($tenant->isAdminAbility())->toBeFalse()
        ->and($tenant->allowedClusterShortuids())->toBe(['abc12345'])
        ->and($tenant->portable)->toBeTrue();
});

test('ClusterAccess userMayAccessCluster admin bypass', function () {
    $admin = new User(['abilities' => ['admin'], 'allowed_clusters' => []]);
    expect(ClusterAccess::userMayAccessCluster($admin, 'anything'))->toBeTrue();
});

test('User assertClusterAllowed denies out-of-scope for non-admin', function () {
    $tenant = new User([
        'abilities' => ['tenant'],
        'allowed_clusters' => ['onlythis'],
    ]);

    expect(fn () => $tenant->assertClusterAllowed('other'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('CdrIndexService filters by accountcodes IN list', function () {
    $path = sys_get_temp_dir().'/pbx3-cdr-'.bin2hex(random_bytes(4)).'.db';
    pbx3MakeCdrDbForClamp($path);
    config(['pbx3_cdr.sqlite_path' => $path]);

    $svc = new CdrIndexService;
    $both = $svc->list(['accountcodes' => ['default', 'tenant1'], 'limit' => 50]);
    expect($both['available'])->toBeTrue()
        ->and($both['total'])->toBe(3);

    $one = $svc->list(['accountcodes' => ['tenant1']]);
    expect($one['total'])->toBe(1)
        ->and($one['rows'][0]['accountcode'])->toBe('tenant1');

    $none = $svc->list(['accountcodes' => ['__none__']]);
    expect($none['total'])->toBe(0);

    @unlink($path);
});
