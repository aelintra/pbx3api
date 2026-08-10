<?php

uses(Tests\TestCase::class);

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\Trunk;

test('Trunk toArray omits password and disapass', function () {
    $trunk = Trunk::make([
        'pkey' => 'T1',
        'cluster' => 'default',
        'password' => 'supersecret',
        'disapass' => 'disasecret',
        'username' => 'trunkuser',
    ]);

    $array = $trunk->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('disapass')
        ->and($array)->toHaveKey('username');

    $json = json_decode($trunk->toJson(), true);
    expect($json)->not->toHaveKey('password')
        ->and($json)->not->toHaveKey('disapass');
});

test('Tenant toArray omits ldappass syspass and spy_pass', function () {
    $tenant = Tenant::make([
        'pkey' => 'TenantA',
        'ldappass' => 'ldapsecret',
        'syspass' => 'syssecret',
        'spy_pass' => 'spysecret',
        'cname' => 'Tenant A',
    ]);

    $array = $tenant->toArray();

    expect($array)->not->toHaveKey('ldappass')
        ->and($array)->not->toHaveKey('syspass')
        ->and($array)->not->toHaveKey('spy_pass')
        ->and($array)->toHaveKey('cname');

    $json = json_decode($tenant->toJson(), true);
    expect($json)->not->toHaveKey('ldappass')
        ->and($json)->not->toHaveKey('syspass')
        ->and($json)->not->toHaveKey('spy_pass');
});

test('Agent toArray omits passwd', function () {
    $agent = Agent::make([
        'pkey' => 'A1',
        'cluster' => 'default',
        'passwd' => 'agentsecret',
        'cname' => 'Agent One',
    ]);

    $array = $agent->toArray();

    expect($array)->not->toHaveKey('passwd')
        ->and($array)->toHaveKey('cname');

    $json = json_decode($agent->toJson(), true);
    expect($json)->not->toHaveKey('passwd');
});
