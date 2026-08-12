<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrQuery;
use App\Services\Ops\GatekeeperOpsClient;
use App\Services\Ops\VelocityOffHoursClock;
use App\Services\Ops\VelocityOffHoursScanner;
use App\Services\Ops\VelocityOrchestrator;
use App\Services\Ops\VelocityIrsfScanner;
use App\Services\Ops\VelocityPhoneActuator;
use App\Services\Ops\VelocityPhoneAttributor;
use App\Services\Ops\VelocityPolicyResolver;
use Illuminate\Support\Facades\Http;

test('VelocityOffHoursClock matches weekend overnight and rejects weekday daytime', function () {
    $clock = new VelocityOffHoursClock;
    $windows = [['dow' => [6, 0], 'start' => '18:00', 'end' => '06:00']];

    $satNight = new DateTimeImmutable('2026-08-08 19:30:00', new DateTimeZone('UTC')); // Saturday
    $sunEarly = new DateTimeImmutable('2026-08-09 02:00:00', new DateTimeZone('UTC')); // Sunday
    $monMorning = new DateTimeImmutable('2026-08-10 10:00:00', new DateTimeZone('UTC')); // Monday
    $satNoon = new DateTimeImmutable('2026-08-08 12:00:00', new DateTimeZone('UTC'));

    expect($clock->isInWindow($satNight, 'UTC', $windows))->toBeTrue()
        ->and($clock->isInWindow($sunEarly, 'UTC', $windows))->toBeTrue()
        ->and($clock->isInWindow($monMorning, 'UTC', $windows))->toBeFalse()
        ->and($clock->isInWindow($satNoon, 'UTC', $windows))->toBeFalse();
});

test('VelocityOffHoursScanner emits velocity_off_hours when window covers now', function () {
    $path = sys_get_temp_dir().'/pbx3-vel-oh-'.bin2hex(random_bytes(4)).'.db';
    $state = sys_get_temp_dir().'/pbx3-vel-oh-state-'.bin2hex(random_bytes(4)).'.json';
    $dow = (int) date('w');

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_enabled' => true,
        'pbx3_ops.velocity_off_hours_enabled' => true,
        'pbx3_ops.velocity_act_enabled' => false,
        'pbx3_ops.velocity_policy_mode' => 'local',
        'pbx3_ops.velocity_threshold' => 99,
        'pbx3_ops.velocity_off_hours_threshold' => 10,
        'pbx3_ops.velocity_off_hours_window_minutes' => 5,
        'pbx3_ops.velocity_quiet_minutes' => 30,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
        'pbx3_ops.velocity_off_hours_tz' => 'UTC',
        'pbx3_ops.velocity_off_hours_windows' => [
            ['dow' => [$dow], 'start' => '00:00', 'end' => '23:59'],
        ],
        'pbx3_ops.velocity_state_path' => $state,
        'pbx3_ops.velocity_instance_id' => 'labinst01',
        'pbx3_ops.velocity_instance_fqdn' => '08jzwn.pbx3.com',
        'pbx3_ops.gatekeeper_url' => 'https://control.example',
        'pbx3_ops.gatekeeper_token' => 'test-token',
        'pbx3_ops.gatekeeper_http_verify' => false,
    ]);

    (new CdrFixtureService(new CdrIndexService))->seed([
        'path' => $path,
        'deck' => 'irsf',
        'count' => 12,
        'src' => '1001',
        'force' => true,
    ]);

    Http::fake([
        'control.example/*' => Http::response(['accepted' => true, 'notified' => true], 200),
    ]);

    $scanner = new VelocityOffHoursScanner(
        new GatekeeperOpsClient,
        new VelocityCdrQuery(new CdrIndexService),
        new VelocityPhoneActuator(new VelocityPhoneAttributor),
        new VelocityOffHoursClock
    );

    $first = $scanner->run();
    expect($first['emitted'])->toBe(1)
        ->and($first['acted'])->toBe(0)
        ->and($first['errors'])->toBe([]);

    Http::assertSent(function ($request) {
        $data = $request->data();
        if ($data === []) {
            $decoded = json_decode($request->body(), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return ($data['type'] ?? null) === 'velocity_off_hours'
            && ($data['transition'] ?? null) === 'down'
            && ($data['extension'] ?? null) === '1001'
            && (int) ($data['count'] ?? 0) === 12;
    });

    $second = $scanner->run();
    expect($second['emitted'])->toBe(0)
        ->and($second['skipped_hysteresis'])->toBe(1);

    @unlink($path);
    @unlink($state);
});

test('VelocityOffHoursScanner ignores premium CDR outside window', function () {
    $path = sys_get_temp_dir().'/pbx3-vel-oh2-'.bin2hex(random_bytes(4)).'.db';
    $state = sys_get_temp_dir().'/pbx3-vel-oh2-state-'.bin2hex(random_bytes(4)).'.json';

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_enabled' => true,
        'pbx3_ops.velocity_off_hours_enabled' => true,
        'pbx3_ops.velocity_act_enabled' => false,
        'pbx3_ops.velocity_policy_mode' => 'local',
        'pbx3_ops.velocity_off_hours_threshold' => 10,
        'pbx3_ops.velocity_off_hours_window_minutes' => 5,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
        'pbx3_ops.velocity_off_hours_tz' => 'UTC',
        // Empty dow list never matches (clock requires listed dow when present).
        'pbx3_ops.velocity_off_hours_windows' => [
            ['dow' => [1], 'start' => '00:00', 'end' => '00:01'],
        ],
        'pbx3_ops.velocity_state_path' => $state,
        'pbx3_ops.velocity_instance_id' => 'labinst01',
        'pbx3_ops.velocity_instance_fqdn' => '08jzwn.pbx3.com',
        'pbx3_ops.gatekeeper_url' => 'https://control.example',
        'pbx3_ops.gatekeeper_token' => 'test-token',
        'pbx3_ops.gatekeeper_http_verify' => false,
    ]);

    // No windows → clock never matches.
    config([
        'pbx3_ops.velocity_off_hours_windows' => [],
    ]);

    (new CdrFixtureService(new CdrIndexService))->seed([
        'path' => $path,
        'deck' => 'irsf',
        'count' => 12,
        'src' => '1001',
        'force' => true,
    ]);

    Http::fake();

    $scanner = new VelocityOffHoursScanner(
        new GatekeeperOpsClient,
        new VelocityCdrQuery(new CdrIndexService),
        new VelocityPhoneActuator(new VelocityPhoneAttributor),
        new VelocityOffHoursClock
    );

    $out = $scanner->run();
    expect($out['emitted'])->toBe(0)
        ->and($out['candidates'])->toBe(0);
    Http::assertNothingSent();

    @unlink($path);
    @unlink($state);
});

test('VelocityOrchestrator runs IRSF and optional off-hours', function () {
    $path = sys_get_temp_dir().'/pbx3-vel-orch-'.bin2hex(random_bytes(4)).'.db';
    $state = sys_get_temp_dir().'/pbx3-vel-orch-state-'.bin2hex(random_bytes(4)).'.json';
    $dow = (int) date('w');

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_enabled' => true,
        'pbx3_ops.velocity_off_hours_enabled' => true,
        'pbx3_ops.velocity_act_enabled' => false,
        'pbx3_ops.velocity_policy_mode' => 'local',
        'pbx3_ops.velocity_threshold' => 10,
        'pbx3_ops.velocity_window_minutes' => 5,
        'pbx3_ops.velocity_off_hours_threshold' => 10,
        'pbx3_ops.velocity_off_hours_window_minutes' => 5,
        'pbx3_ops.velocity_quiet_minutes' => 30,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
        'pbx3_ops.velocity_off_hours_windows' => [
            ['dow' => [$dow], 'start' => '00:00', 'end' => '23:59'],
        ],
        'pbx3_ops.velocity_state_path' => $state,
        'pbx3_ops.velocity_instance_id' => 'labinst01',
        'pbx3_ops.velocity_instance_fqdn' => '08jzwn.pbx3.com',
        'pbx3_ops.gatekeeper_url' => 'https://control.example',
        'pbx3_ops.gatekeeper_token' => 'test-token',
        'pbx3_ops.gatekeeper_http_verify' => false,
    ]);

    (new CdrFixtureService(new CdrIndexService))->seed([
        'path' => $path,
        'deck' => 'irsf',
        'count' => 12,
        'src' => '1001',
        'force' => true,
    ]);

    Http::fake([
        'control.example/*' => Http::response(['accepted' => true, 'notified' => true], 200),
    ]);

    $orch = new VelocityOrchestrator(
        new VelocityPolicyResolver,
        new VelocityIrsfScanner(
            new GatekeeperOpsClient,
            new VelocityCdrQuery(new CdrIndexService),
            new VelocityPhoneActuator(new VelocityPhoneAttributor),
            new VelocityPolicyResolver
        ),
        new VelocityOffHoursScanner(
            new GatekeeperOpsClient,
            new VelocityCdrQuery(new CdrIndexService),
            new VelocityPhoneActuator(new VelocityPhoneAttributor),
            new VelocityOffHoursClock
        )
    );

    $out = $orch->run();
    expect($out['emitted'])->toBe(2)
        ->and($out['detectors']['irsf']['emitted'])->toBe(1)
        ->and($out['detectors']['off_hours']['emitted'])->toBe(1);

    $types = [];
    Http::assertSent(function ($request) use (&$types) {
        $data = $request->data();
        if ($data === []) {
            $decoded = json_decode($request->body(), true);
            $data = is_array($decoded) ? $decoded : [];
        }
        $types[] = $data['type'] ?? '';

        return true;
    });
    expect($types)->toContain('velocity_irsf')
        ->and($types)->toContain('velocity_off_hours');

    @unlink($path);
    @unlink($state);
});
