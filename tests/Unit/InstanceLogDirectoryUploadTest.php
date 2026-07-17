<?php

use App\Services\Directory\InstanceLogDirectoryUpload;

test('isRotatedSegment accepts numbered syslog and rejects live file', function () {
    $u = new InstanceLogDirectoryUpload;

    expect($u->isRotatedSegment('syslog.1', 'syslog'))->toBeTrue()
        ->and($u->isRotatedSegment('syslog.1.gz', 'syslog'))->toBeTrue()
        ->and($u->isRotatedSegment('syslog', 'syslog'))->toBeFalse();
});

test('isRotatedSegment accepts Asterisk messages and CDR rotations', function () {
    $u = new InstanceLogDirectoryUpload;

    expect($u->isRotatedSegment('messages.1', 'asterisk-messages'))->toBeTrue()
        ->and($u->isRotatedSegment('messages.2.gz', 'asterisk-messages'))->toBeTrue()
        ->and($u->isRotatedSegment('messages', 'asterisk-messages'))->toBeFalse()
        ->and($u->isRotatedSegment('Master.csv.1', 'cdr'))->toBeTrue()
        ->and($u->isRotatedSegment('Master.csv.1.gz', 'cdr'))->toBeTrue()
        ->and($u->isRotatedSegment('Master.csv', 'cdr'))->toBeFalse();
});

test('buildObjectKey uses class and stamp path under instances ksuid', function () {
    $dir = sys_get_temp_dir().'/pbx3-logship-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/Master.csv.1';
    file_put_contents($path, "hdr\nrow\n");
    touch($path, strtotime('2026-07-17 12:34:56 UTC'));

    $u = new InstanceLogDirectoryUpload;
    $key = $u->buildObjectKey('testKsuid123', 'cdr', $path);

    expect($key)->toStartWith('instances/testKsuid123/logs/cdr/')
        ->and($key)->toEndWith('/Master.csv.1')
        ->and($key)->toContain('20260717T123456Z');

    @unlink($path);
    @rmdir($dir);
});
