<?php

use App\Services\Snapshot\SnapshotRetention;

test('planPrune sorts newest first and ignores junk', function () {
    $plan = SnapshotRetention::planPrune([
        'junk.txt',
        'pbx3.db.1',
        'sqlite.db.10',
        'pbx3.db.5',
        'not-a-snap.db.3',
    ], 9);

    expect($plan['keep'])->toBe(['sqlite.db.10', 'pbx3.db.5', 'pbx3.db.1'])
        ->and($plan['remove'])->toBe([]);
});

test('planPrune keeps newest N and lists older for removal', function () {
    $plan = SnapshotRetention::planPrune([
        'pbx3.db.1',
        'pbx3.db.2',
        'pbx3.db.3',
        'pbx3.db.4',
    ], 2);

    expect($plan['keep'])->toBe(['pbx3.db.4', 'pbx3.db.3'])
        ->and($plan['remove'])->toBe(['pbx3.db.2', 'pbx3.db.1']);
});

test('planPrune maxCount below 1 is a no-op', function () {
    $plan = SnapshotRetention::planPrune(['pbx3.db.1', 'pbx3.db.2'], 0);

    expect($plan['keep'])->toBe([])
        ->and($plan['remove'])->toBe([]);
});

test('pruneExcess uses injectable dir and delete callback', function () {
    $dir = sys_get_temp_dir().'/pbx3-snap-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    foreach (['pbx3.db.1', 'pbx3.db.2', 'pbx3.db.3', 'readme.txt'] as $name) {
        file_put_contents($dir.'/'.$name, 'x');
    }

    $deleted = [];
    $retention = new SnapshotRetention($dir, function (string $basename) use (&$deleted): bool {
        $deleted[] = $basename;

        return true;
    });

    $removed = $retention->pruneExcess(2);

    expect($removed)->toBe(['pbx3.db.1'])
        ->and($deleted)->toBe(['pbx3.db.1']);

    foreach (glob($dir.'/*') as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});
