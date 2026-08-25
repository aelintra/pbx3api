<?php

uses(Tests\TestCase::class);

use App\Models\Extension;
use App\Services\Cdr\CdrFixtureService;
use App\Services\Cdr\CdrIndexService;
use App\Services\Cdr\VelocityCdrQuery;
use App\Services\Ops\GatekeeperOpsClient;
use App\Services\Ops\VelocityIrsfScanner;
use App\Services\Ops\VelocityPhoneActuator;
use App\Services\Ops\VelocityPhoneAttributor;
use App\Services\Ops\VelocityPolicyResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function pbx3VelocityActuator(): VelocityPhoneActuator
{
    return new VelocityPhoneActuator(new VelocityPhoneAttributor);
}

function pbx3VelocityScanner(): VelocityIrsfScanner
{
    config(['pbx3_ops.velocity_policy_mode' => 'local']);

    return new VelocityIrsfScanner(
        new GatekeeperOpsClient,
        new VelocityCdrQuery(new CdrIndexService),
        pbx3VelocityActuator(),
        new VelocityPolicyResolver
    );
}

function pbx3VelocityEnsureIpphoneSchema(): void
{
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    // Reconnect so Laravel actually uses :memory:
    \Illuminate\Support\Facades\DB::purge('sqlite');
    \Illuminate\Support\Facades\DB::reconnect('sqlite');

    Schema::dropIfExists('ipphone');
    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable()->unique();
        $table->string('pkey');
        $table->integer('abstimeout')->default(1440);
        $table->string('active')->default('YES');
        $table->string('callbackto')->default('desk');
        $table->string('cellphone')->nullable();
        $table->string('celltwin')->default('OFF');
        $table->string('cluster')->default('default');
        $table->string('devicerec')->default('default');
        $table->string('protocol')->default('IPV4');
        $table->string('transport')->default('udp');
        $table->string('technology')->nullable();
        $table->string('named_call_group')->default('ALL');
        $table->string('named_pickup_group')->default('ALL');
        $table->string('z_updater')->default('system');
    });
}

test('VelocityIrsfScanner emits once then hysteresis skips', function () {
    $path = sys_get_temp_dir().'/pbx3-vel-scan-'.bin2hex(random_bytes(4)).'.db';
    $state = sys_get_temp_dir().'/pbx3-vel-state-'.bin2hex(random_bytes(4)).'.json';

    config([
        'pbx3_cdr.sqlite_path' => $path,
        'pbx3_ops.velocity_enabled' => true,
        'pbx3_ops.velocity_act_enabled' => false,
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

    $scanner = pbx3VelocityScanner();

    $first = $scanner->run();
    expect($first['emitted'])->toBe(1)
        ->and($first['skipped_hysteresis'])->toBe(0)
        ->and($first['acted'])->toBe(0)
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
            && ($data['auto_block'] ?? null) === false
            && str_starts_with((string) (($data['masked_prefixes'][0] ?? '')), '0900');
    });

    $second = $scanner->run();
    expect($second['emitted'])->toBe(0)
        ->and($second['skipped_hysteresis'])->toBe(1);
    Http::assertSentCount(1);

    @unlink($path);
    @unlink($state);
});

test('VelocityIrsfScanner maskDestination truncates digits', function () {
    $scanner = pbx3VelocityScanner();
    expect($scanner->maskDestination('09001234567'))->toBe('09001***')
        ->and($scanner->maskDestination('+44192'))->toBe('+4419***');
});

test('VelocityIrsfScanner no-ops when disabled', function () {
    config(['pbx3_ops.velocity_enabled' => false]);
    Http::fake();
    $scanner = pbx3VelocityScanner();
    expect($scanner->run()['scanned'])->toBeFalse();
    Http::assertNothingSent();
});

test('VelocityPhoneAttributor maps src pkey uniquely and fails when ambiguous', function () {
    pbx3VelocityEnsureIpphoneSchema();
    Extension::query()->create([
        'id' => 'id1',
        'shortuid' => 'aaaa1111',
        'pkey' => '1001',
        'active' => 'YES',
        'cluster' => 'labtenant',
    ]);
    Extension::query()->create([
        'id' => 'id2',
        'shortuid' => 'bbbb2222',
        'pkey' => '1001',
        'active' => 'YES',
        'cluster' => 'othertenant',
    ]);

    $attr = new VelocityPhoneAttributor;
    $ok = $attr->attribute('1001', [
        ['channel' => 'PJSIP/aaaa1111-00000001', 'accountcode' => 'labtenant'],
    ], 'labtenant');
    expect($ok['phone'])->not->toBeNull()
        ->and($ok['phone']->shortuid)->toBe('aaaa1111')
        ->and($ok['reason'])->toBe('channel_shortuid');

    $ambiguous = $attr->attribute('1001', [
        ['channel' => 'PJSIP/1001-00000001'],
    ], '');
    expect($ambiguous['phone'])->toBeNull()
        ->and($ambiguous['reason'])->toBe('ambiguous_src_pkey');
});

test('VelocityPhoneActuator sets active=NO and clears follow-me when attributed', function () {
    pbx3VelocityEnsureIpphoneSchema();
    Extension::query()->create([
        'id' => 'idact',
        'shortuid' => 'cccc3333',
        'pkey' => '1001',
        'active' => 'YES',
        'celltwin' => 'ON',
        'cellphone' => '441234',
        'callbackto' => 'cell',
        'cluster' => 'labtenant',
        'z_updater' => 'system',
    ]);

    config([
        'pbx3_ops.velocity_act_enabled' => true,
        'pbx3_ops.velocity_skip_asterisk' => true,
        'pbx3_ops.velocity_allowlist' => '',
    ]);

    $act = pbx3VelocityActuator()->actOnSurge('1001', [
        ['channel' => 'PJSIP/cccc3333-00000001', 'accountcode' => 'labtenant', 'src' => '1001'],
    ], 'labtenant');

    expect($act['applied'])->toBeTrue()
        ->and($act['active_set'])->toBeTrue()
        ->and($act['extension_pkey'])->toBe('1001');

    $row = Extension::query()->where('id', 'idact')->first();
    expect($row->active)->toBe('NO')
        ->and($row->celltwin)->toBe('OFF')
        ->and($row->callbackto)->toBe('desk')
        ->and($row->cellphone)->toBeNull()
        ->and($row->z_updater)->toBe('velocity');
});

test('VelocityPhoneActuator refuses uncertain attribution', function () {
    pbx3VelocityEnsureIpphoneSchema();
    config([
        'pbx3_ops.velocity_act_enabled' => true,
        'pbx3_ops.velocity_skip_asterisk' => true,
    ]);

    $act = pbx3VelocityActuator()->actOnSurge('9999', [
        ['channel' => 'PJSIP/unknown-00000001', 'src' => '9999'],
    ], '');

    expect($act['applied'])->toBeFalse()
        ->and($act['skipped_reason'])->toBe('uncertain_attribution');
});
