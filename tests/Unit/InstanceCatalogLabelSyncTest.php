<?php

namespace Tests\Unit;

use App\Models\Sysglobal;
use App\Services\Fleet\GatekeeperCatalogClient;
use App\Services\Fleet\InstanceCatalogLabelSync;
use Mockery;
use Tests\TestCase;

test('catalog label sync no-ops when gatekeeper not configured', function () {
    $client = Mockery::mock(GatekeeperCatalogClient::class);
    $client->shouldReceive('isConfigured')->once()->andReturn(false);
    $client->shouldNotReceive('patchInstance');

    $g = new Sysglobal;
    $g->id = 'ksuid1';
    $g->sitename = 'Kildare';
    $g->shortuid = '08jzwn';

    (new InstanceCatalogLabelSync($client))->syncSitenameOrRevert($g, 'old');
});

test('catalog label sync patches label from sitename', function () {
    $client = Mockery::mock(GatekeeperCatalogClient::class);
    $client->shouldReceive('isConfigured')->once()->andReturn(true);
    $client->shouldReceive('patchInstance')
        ->once()
        ->with('ksuid1', Mockery::on(function (array $patch) {
            return ($patch['label'] ?? null) === 'Kildare'
                && ($patch['updated_by'] ?? null) === 'node-sitename-sync';
        }))
        ->andReturn(['ok' => true]);

    $g = new Sysglobal;
    $g->id = 'ksuid1';
    $g->sitename = 'Kildare';
    $g->shortuid = '08jzwn';

    (new InstanceCatalogLabelSync($client))->syncSitenameOrRevert($g, 'old');
});

test('catalog label sync uses shortuid when sitename empty', function () {
    $client = Mockery::mock(GatekeeperCatalogClient::class);
    $client->shouldReceive('isConfigured')->once()->andReturn(true);
    $client->shouldReceive('patchInstance')
        ->once()
        ->with('ksuid1', Mockery::on(fn (array $p) => ($p['label'] ?? null) === '08jzwn'))
        ->andReturn([]);

    $g = new Sysglobal;
    $g->id = 'ksuid1';
    $g->sitename = '';
    $g->shortuid = '08jzwn';

    (new InstanceCatalogLabelSync($client))->syncSitenameOrRevert($g, null);
});
