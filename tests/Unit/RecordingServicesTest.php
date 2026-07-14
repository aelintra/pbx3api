<?php

use App\Services\Recordings\RecordingFilenameParser;
use App\Services\Recordings\RecordingPathHelper;

test('parses regular call filename', function () {
    $parser = new RecordingFilenameParser;
    $row = $parser->parse('9wvvnb', '1716123456-9wvvnb-5551234-5559876.wav');

    expect($row['epoch'])->toBe(1716123456)
        ->and($row['dnid'])->toBe('5551234')
        ->and($row['callerid'])->toBe('5559876')
        ->and($row['is_queue'])->toBeFalse();
});

test('parses queue filename', function () {
    $parser = new RecordingFilenameParser;
    $row = $parser->parse('9wvvnb', '1716123456-9wvvnb-sales-101-5559876.wav');

    expect($row['queue'])->toBe('sales')
        ->and($row['extension'])->toBe('101')
        ->and($row['is_queue'])->toBeTrue();
});

test('archive relative path uses utc date folders', function () {
    $paths = new RecordingPathHelper;
    $rel = $paths->archiveRelativePath('9wvvnb', 1716123456, 'test.wav');

    expect($rel)->toBe('9wvvnb/2024/05/19/test.wav');
});

test('s3 object key uses tenants recordings media date layout', function () {
    $paths = new RecordingPathHelper;
    $key = $paths->s3ObjectKey('9wvvnb', 1716123456, '1716123456-9wvvnb-1000-2000.wav');

    expect($key)->toBe('tenants/9wvvnb/recordings/media/2024/05/19/1716123456-9wvvnb-1000-2000.wav');
});

test('legacy id round-trips spool path', function () {
    $paths = new RecordingPathHelper;
    $id = $paths->legacyIdFromSpoolPath('9wvvnb', 'call.wav');
    $decoded = $paths->decodeLegacyId($id);

    expect($decoded)->toBe('9wvvnb/call.wav');
});

test('ksuid ids are detected', function () {
    $paths = new RecordingPathHelper;
    $ksuid = '0o5Fs0EELNVK5ZMKO0XLVZbnjGx'; // 27 chars

    expect($paths->isKsuidId($ksuid))->toBeTrue()
        ->and($paths->isKsuidId('not-a-ksuid'))->toBeFalse();
});
