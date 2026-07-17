<?php

uses(Tests\TestCase::class);

use App\Services\Directory\InstanceLogArchiveService;
use App\Services\Directory\LogRetentionService;

test('LogRetentionService get returns env defaults without override', function () {
    $path = sys_get_temp_dir().'/pbx3-ret-'.bin2hex(random_bytes(4)).'.json';
    config(['pbx3_logs.retention_override_path' => $path]);
    config([
        'pbx3_logs.local_days' => ['syslog' => 7, 'asterisk-messages' => 7, 'cdr' => 7],
        'pbx3_logs.s3_maxage_days' => ['syslog' => 30, 'asterisk-messages' => 30, 'cdr' => 60],
    ]);

    $svc = new LogRetentionService;
    $got = $svc->get();

    expect($got['local_days']['syslog'])->toBe(7)
        ->and($got['s3_maxage_days']['cdr'])->toBe(60)
        ->and($got['has_override'])->toBeFalse();
});

test('LogRetentionService put writes override and merges', function () {
    $path = sys_get_temp_dir().'/pbx3-ret-'.bin2hex(random_bytes(4)).'.json';
    config(['pbx3_logs.retention_override_path' => $path]);
    config([
        'pbx3_logs.local_days' => ['syslog' => 7, 'asterisk-messages' => 7, 'cdr' => 7],
        'pbx3_logs.s3_maxage_days' => ['syslog' => 30, 'asterisk-messages' => 30, 'cdr' => 60],
        'pbx3_directory.org_bucket' => '',
    ]);

    $svc = new LogRetentionService;
    $got = $svc->put(['local_days' => ['syslog' => 14], 's3_maxage_days' => ['cdr' => 90]]);

    expect($got['local_days']['syslog'])->toBe(14)
        ->and($got['local_days']['cdr'])->toBe(7)
        ->and($got['s3_maxage_days']['cdr'])->toBe(90)
        ->and($got['has_override'])->toBeTrue()
        ->and(is_file($path))->toBeTrue();

    @unlink($path);
});

test('LogRetentionService put rejects out of range', function () {
    $path = sys_get_temp_dir().'/pbx3-ret-'.bin2hex(random_bytes(4)).'.json';
    config(['pbx3_logs.retention_override_path' => $path]);

    $svc = new LogRetentionService;
    expect(fn () => $svc->put(['local_days' => ['syslog' => 9999]]))
        ->toThrow(InvalidArgumentException::class);
});

test('InstanceLogArchiveService rejects keys outside instance prefix', function () {
    $svc = new class extends InstanceLogArchiveService
    {
        public function instanceId(): string
        {
            return 'myKsuid';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };

    expect(fn () => $svc->presignedDownloadUrl('instances/other/logs/syslog/x/y'))
        ->toThrow(InvalidArgumentException::class);
});
