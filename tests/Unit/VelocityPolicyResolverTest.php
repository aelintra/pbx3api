<?php

uses(Tests\TestCase::class);

use App\Services\Ops\VelocityPolicyResolver;
use Illuminate\Support\Facades\Storage;

test('VelocityPolicyResolver local mode leaves env knobs alone', function () {
    config([
        'pbx3_ops.velocity_policy_mode' => 'local',
        'pbx3_ops.velocity_threshold' => 7,
        'pbx3_directory.org_bucket' => 'should-not-touch',
    ]);

    $out = (new VelocityPolicyResolver)->apply();
    expect($out['source'])->toBe('local')
        ->and(config('pbx3_ops.velocity_threshold'))->toBe(7);
});

test('VelocityPolicyResolver applies S3 policy and caches', function () {
    $cache = sys_get_temp_dir().'/ops-velocity-policy-'.uniqid('', true).'.json';
    @unlink($cache);

    Storage::fake('pbx3_org');
    Storage::disk('pbx3_org')->put(VelocityPolicyResolver::S3_KEY, json_encode([
        'version' => 1,
        'updated_at' => '2026-08-11T00:00:00Z',
        'irsf' => [
            'n' => 3,
            't_minutes' => 2,
            'q_minutes' => 9,
            'prefixes' => ['0900', '070'],
            'act_enabled' => true,
        ],
        'detectors' => ['irsf' => true, 'off_hours' => false],
        'allowlist_extensions' => ['vip1'],
    ]));

    config([
        'pbx3_ops.velocity_policy_mode' => 'fleet',
        'pbx3_ops.velocity_policy_cache_path' => $cache,
        'pbx3_directory.org_bucket' => 'lab-bucket',
        'pbx3_ops.velocity_threshold' => 10,
        'pbx3_ops.velocity_act_enabled' => false,
    ]);

    $out = (new VelocityPolicyResolver)->apply();
    expect($out['source'])->toBe('s3')
        ->and(config('pbx3_ops.velocity_threshold'))->toBe(3)
        ->and(config('pbx3_ops.velocity_window_minutes'))->toBe(2)
        ->and(config('pbx3_ops.velocity_quiet_minutes'))->toBe(9)
        ->and(config('pbx3_ops.velocity_prefixes'))->toBe('0900,070')
        ->and(config('pbx3_ops.velocity_act_enabled'))->toBeTrue()
        ->and(config('pbx3_ops.velocity_allowlist'))->toBe('vip1')
        ->and(is_file($cache))->toBeTrue();

    @unlink($cache);
});

test('VelocityPolicyResolver falls back to cache then env', function () {
    $cache = sys_get_temp_dir().'/ops-velocity-policy-'.uniqid('', true).'.json';
    file_put_contents($cache, json_encode([
        'version' => 1,
        'irsf' => [
            'n' => 4,
            't_minutes' => 1,
            'q_minutes' => 1,
            'prefixes' => ['0900'],
            'act_enabled' => false,
        ],
    ]));

    Storage::fake('pbx3_org'); // empty → get throws

    config([
        'pbx3_ops.velocity_policy_mode' => 'fleet',
        'pbx3_ops.velocity_policy_cache_path' => $cache,
        'pbx3_directory.org_bucket' => 'lab-bucket',
        'pbx3_ops.velocity_threshold' => 99,
    ]);

    $out = (new VelocityPolicyResolver)->apply();
    expect($out['source'])->toBe('cache')
        ->and(config('pbx3_ops.velocity_threshold'))->toBe(4);

    @unlink($cache);

    config([
        'pbx3_ops.velocity_policy_cache_path' => $cache,
        'pbx3_ops.velocity_threshold' => 11,
    ]);
    $out2 = (new VelocityPolicyResolver)->apply();
    expect($out2['source'])->toBe('env')
        ->and(config('pbx3_ops.velocity_threshold'))->toBe(11);
});
