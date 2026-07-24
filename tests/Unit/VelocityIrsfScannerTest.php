<?php

uses(Tests\TestCase::class);

use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrQuery;
use App\Services\Ops\GatekeeperOpsClient;
use App\Services\Ops\VelocityIrsfScanner;
use Illuminate\Support\Facades\Http;

test('VelocityIrsfScanner emits once then hysteresis skips', function () {
    $path = sys_get_temp_dir().'/pbx3-vel-scan-'.bin2hex(random_bytes(4)).'.db';
    $state = sys_get_temp_dir().'/pbx3-vel-state-'.bin2hex(random_bytes(4)).'.json';

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_enabled' => true,
        'pbx3_ops.velocity_threshold' => 10,
        'pbx3_ops.velocity_window_minutes' => 5,
        'pbx3_ops.velocity_quiet_minutes' => 30,
        'pbx3_ops.velocity_prefixes' => CdrFixtureService::LAB_PREMIUM_PREFIX,
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

    $scanner = new VelocityIrsfScanner(new GatekeeperOpsClient, new VelocityCdrQuery(new CdrIndexService));

    $first = $scanner->run();
    expect($first['emitted'])->toBe(1)
        ->and($first['skipped_hysteresis'])->toBe(0)
        ->and($first['errors'])->toBe([]);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $data = $request->data();
        if ($data === []) {
            $decoded = json_decode($request->body(), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return str_contains($request->url(), '/api/v1/ops-events')
            && ($data['type'] ?? null) === 'velocity_irsf'
            && ($data['transition'] ?? null) === 'down'
            && ($data['extension'] ?? null) === '1001'
            && (int) ($data['count'] ?? 0) === 12
            && str_starts_with((string) (($data['masked_prefixes'][0] ?? '')), '00900');
    });

    $second = $scanner->run();
    expect($second['emitted'])->toBe(0)
        ->and($second['skipped_hysteresis'])->toBe(1);
    Http::assertSentCount(1);

    @unlink($path);
    @unlink($state);
});

test('VelocityIrsfScanner maskDestination truncates digits', function () {
    $scanner = new VelocityIrsfScanner(new GatekeeperOpsClient, new VelocityCdrQuery(new CdrIndexService));
    expect($scanner->maskDestination('009001234567'))->toBe('00900***')
        ->and($scanner->maskDestination('+44192'))->toBe('+4419***');
});

test('VelocityIrsfScanner no-ops when disabled', function () {
    config(['pbx3_ops.velocity_enabled' => false]);
    Http::fake();
    $scanner = new VelocityIrsfScanner(new GatekeeperOpsClient, new VelocityCdrQuery(new CdrIndexService));
    expect($scanner->run()['scanned'])->toBeFalse();
    Http::assertNothingSent();
});
