<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $idpwgen = dirname(__DIR__, 2) . '/../pbx3/pbx3-1/opt/pbx3/golang/idpwgen';
    if (is_executable($idpwgen)) {
        putenv('IDPWGEN_PATH=' . $idpwgen);
        $_ENV['IDPWGEN_PATH'] = $idpwgen;
    }

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

    Schema::create('clid_block', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
        $table->string('action')->default('hangup');
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
        ['id' => 'tenantbksuid00000000000001', 'shortuid' => 'tenantb1', 'pkey' => 'TenantB'],
    ]);
});

function pbx3ClidBlockTenantUser(array $allowedClusters): User
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

function pbx3ClidBlockAdminUser(): User
{
    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'abilities' => ['admin'],
        'allowed_clusters' => [],
        'portable' => false,
    ]);
    $user->id = 1;
    Sanctum::actingAs($user, ['admin']);

    return $user;
}

test('tenant user can create clid block for allowed cluster', function () {
    pbx3ClidBlockTenantUser(['tenanta1']);

    $response = $this->postJson('/api/clidblocks', [
        'cluster' => 'TenantA',
        'pkey' => '+44 1924 918 076',
        'description' => 'nuisance caller',
        'active' => 'YES',
    ]);

    $response->assertSuccessful();
    $response->assertJsonFragment(['pkey' => '441924918076', 'cluster' => 'tenanta1']);

    expect(DB::table('clid_block')->where('pkey', '441924918076')->count())->toBe(1);
});

test('tenant user cannot create clid block for other cluster', function () {
    pbx3ClidBlockTenantUser(['tenanta1']);

    $response = $this->postJson('/api/clidblocks', [
        'cluster' => 'TenantB',
        'pkey' => '441924918076',
    ]);

    $response->assertForbidden();
});

test('rejects duplicate clid per tenant', function () {
    pbx3ClidBlockAdminUser();

    DB::table('clid_block')->insert([
        'id' => 'blk0000000000000000000001',
        'shortuid' => 'clidblk1',
        'pkey' => '441924918076',
        'cluster' => 'tenanta1',
        'active' => 'YES',
        'action' => 'hangup',
    ]);

    $response = $this->postJson('/api/clidblocks', [
        'cluster' => 'TenantA',
        'pkey' => '441924918076',
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('pkey');
});
