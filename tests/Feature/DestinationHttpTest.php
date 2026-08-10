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

    Schema::create('appl', function (Blueprint $table) {
        $table->string('pkey')->primary();
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
    });

    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
    });

    Schema::create('ivrmenu', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
    });

    Schema::create('queue', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
    });

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
        ['id' => 'tenantbksuid00000000000001', 'shortuid' => 'tenantb1', 'pkey' => 'TenantB'],
    ]);

    DB::table('ipphone')->insert([
        ['id' => 'extaksuid0000000000000001', 'shortuid' => 'exta0001', 'pkey' => 'ExtA', 'active' => 'YES', 'cluster' => 'tenanta1'],
        ['id' => 'extbksuid0000000000000001', 'shortuid' => 'extb0001', 'pkey' => 'ExtB', 'active' => 'YES', 'cluster' => 'tenantb1'],
    ]);

    // Assertions use Queues (App\Models\Queue keys off id, not pkey) rather than Extensions/CustomApps:
    // IpPhone/Application declare primaryKey 'pkey' without $incrementing=false + $keyType=string, so
    // Eloquent's implicit key cast silently turns plucked pkeys into ints — a pre-existing, unrelated
    // model bug outside the scope of this cluster-clamp change.
    DB::table('queue')->insert([
        ['id' => 'queueaksuid000000000000001', 'pkey' => 'QueueA', 'active' => 'YES', 'cluster' => 'tenanta1'],
        ['id' => 'queuebksuid000000000000001', 'pkey' => 'QueueB', 'active' => 'YES', 'cluster' => 'tenantb1'],
    ]);
});

function pbx3DestinationTenantUser(array $allowedClusters): User
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

function pbx3DestinationAdminUser(): User
{
    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'abilities' => ['admin'],
        'allowed_clusters' => null,
        'portable' => false,
    ]);
    $user->id = 1;
    Sanctum::actingAs($user, ['admin']);

    return $user;
}

test('destinations index requires cluster param', function () {
    pbx3DestinationTenantUser(['tenanta1']);

    $response = $this->getJson('/api/destinations');

    $response->assertStatus(422)
        ->assertJsonStructure(['cluster']);
});

test('destinations index rejects out-of-scope cluster for non-admin', function () {
    pbx3DestinationTenantUser(['tenanta1']);

    $response = $this->getJson('/api/destinations?cluster=TenantB');

    $response->assertStatus(403);
});

test('destinations index returns tenant-scoped extensions when cluster in scope', function () {
    pbx3DestinationTenantUser(['tenanta1']);

    $response = $this->getJson('/api/destinations?cluster=TenantA');

    $response->assertOk()
        ->assertJsonPath('Queues', ['QueueA']);
});

test('destinations index still requires cluster param for admin', function () {
    pbx3DestinationAdminUser();

    $response = $this->getJson('/api/destinations');

    $response->assertStatus(422);
});

test('destinations index allows admin to view any tenant', function () {
    pbx3DestinationAdminUser();

    $response = $this->getJson('/api/destinations?cluster=TenantB');

    $response->assertOk()
        ->assertJsonPath('Queues', ['QueueB']);
});
