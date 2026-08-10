<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    config(['database.connections.sqlite.prefix' => '']);
    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::create('cluster', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey')->nullable();
    });

    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
    });

    Schema::create('cos', function (Blueprint $table) {
        $table->string('pkey')->primary();
        $table->string('cluster')->nullable();
    });

    Schema::create('ipphonecosclosed', function (Blueprint $table) {
        $table->string('cluster')->nullable();
        $table->string('active')->default('YES');
        $table->string('ipphone_pkey');
        $table->string('cos_pkey');
    });

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
        ['id' => 'tenantbksuid00000000000001', 'shortuid' => 'tenantb1', 'pkey' => 'TenantB'],
    ]);

    DB::table('ipphone')->insert([
        ['id' => 'extaksuid0000000000000001', 'shortuid' => 'exta0001', 'pkey' => 'ExtA', 'active' => 'YES', 'cluster' => 'tenanta1'],
        ['id' => 'extbksuid0000000000000001', 'shortuid' => 'extb0001', 'pkey' => 'ExtB', 'active' => 'YES', 'cluster' => 'tenantb1'],
    ]);

    DB::table('cos')->insert([
        ['pkey' => 'ClosedRule', 'cluster' => 'tenanta1'],
        ['pkey' => 'OtherRule', 'cluster' => 'tenantb1'],
    ]);

    DB::table('ipphonecosclosed')->insert([
        ['cluster' => 'tenanta1', 'ipphone_pkey' => 'ExtA', 'cos_pkey' => 'ClosedRule'],
        ['cluster' => 'tenantb1', 'ipphone_pkey' => 'ExtB', 'cos_pkey' => 'ClosedRule'],
    ]);
});

function pbx3CosTenantUser(array $allowedClusters): User
{
    $user = new User([
        'name' => 'Tenant User',
        'email' => 'tenantuser@example.com',
        'abilities' => ['tenant'],
        'allowed_clusters' => $allowedClusters,
        'portable' => false,
    ]);
    $user->id = 2;
    Sanctum::actingAs($user, ['tenant']);

    return $user;
}

test('coscloses index only returns rows within the caller cluster scope', function () {
    pbx3CosTenantUser(['tenanta1']);

    $response = $this->getJson('/api/coscloses');

    $response->assertOk();
    $rows = $response->json();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['ipphone_pkey'])->toBe('ExtA');
});

test('coscloses show is forbidden for out-of-scope row', function () {
    pbx3CosTenantUser(['tenanta1']);

    $response = $this->getJson('/api/coscloses/ExtB');

    $response->assertStatus(403);
});

test('coscloses show allows in-scope row', function () {
    pbx3CosTenantUser(['tenanta1']);

    $response = $this->getJson('/api/coscloses/ExtA');

    $response->assertOk()
        ->assertJsonPath('ipphone_pkey', 'ExtA');
});

test('coscloses delete is forbidden for out-of-scope row', function () {
    pbx3CosTenantUser(['tenanta1']);

    $response = $this->deleteJson('/api/coscloses/ExtB');

    $response->assertStatus(403);
    expect(DB::table('ipphonecosclosed')->where('ipphone_pkey', 'ExtB')->exists())->toBeTrue();
});

test('coscloses save rejects extension outside caller cluster scope', function () {
    pbx3CosTenantUser(['tenanta1']);

    $response = $this->postJson('/api/coscloses', [
        'ipphone_pkey' => 'ExtB',
        'cos_pkey' => 'OtherRule',
    ]);

    $response->assertStatus(403);
});

test('coscloses save sets cluster from the extension for in-scope extension', function () {
    pbx3CosTenantUser(['tenanta1']);
    DB::table('ipphone')->insert(['id' => 'extaksuid0000000000000002', 'shortuid' => 'exta0002', 'pkey' => 'ExtA2', 'active' => 'YES', 'cluster' => 'tenanta1']);

    $response = $this->postJson('/api/coscloses', [
        'ipphone_pkey' => 'ExtA2',
        'cos_pkey' => 'ClosedRule',
    ]);

    $response->assertStatus(201);
    expect(DB::table('ipphonecosclosed')->where('ipphone_pkey', 'ExtA2')->value('cluster'))->toBe('tenanta1');
});
