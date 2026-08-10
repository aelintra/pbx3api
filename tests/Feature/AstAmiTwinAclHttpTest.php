<?php

use App\Http\Controllers\AstAmiController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Records PutDB/DelDB calls instead of touching a real Asterisk AMI socket.
 */
class FakeTwinAmiHandle
{
    public array $putCalls = [];

    public array $delCalls = [];

    public function PutDB($family, $key, $value)
    {
        $this->putCalls[] = [$family, $key, $value];

        return true;
    }

    public function DelDB($family, $key)
    {
        $this->delCalls[] = [$family, $key];

        return true;
    }

    public function logout()
    {
    }
}

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

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
        ['id' => 'tenantbksuid00000000000001', 'shortuid' => 'tenantb1', 'pkey' => 'TenantB'],
    ]);

    DB::table('ipphone')->insert([
        ['id' => 'extaksuid0000000000000001', 'shortuid' => 'exta0001', 'pkey' => '100', 'active' => 'YES', 'cluster' => 'tenanta1'],
        ['id' => 'extbksuid0000000000000001', 'shortuid' => 'extb0001', 'pkey' => '200', 'active' => 'YES', 'cluster' => 'tenantb1'],
    ]);
});

function pbx3BindFakeTwinAmi(FakeTwinAmiHandle $fake): void
{
    app()->bind(AstAmiController::class, function () use ($fake) {
        return new class($fake) extends AstAmiController
        {
            public function __construct(private FakeTwinAmiHandle $fake)
            {
            }

            protected function amiHandle()
            {
                return $this->fake;
            }
        };
    });
}

test('twin dbput is rejected when unauthenticated', function () {
    $response = $this->putJson('/api/auth/astamis/DBput/srktwin/exta0001/ON');

    $response->assertStatus(401);
});

test('twin dbput is rejected for user without admin or tenant ability', function () {
    $user = new User([
        'name' => 'Recordings User',
        'email' => 'recuser@example.com',
        'abilities' => ['recordings'],
        'allowed_clusters' => [],
        'portable' => false,
    ]);
    $user->id = 3;
    Sanctum::actingAs($user, ['recordings']);

    $response = $this->putJson('/api/auth/astamis/DBput/srktwin/exta0001/ON');

    $response->assertStatus(403);
});

test('twin dbput rejects key for extension outside caller cluster scope', function () {
    $fake = new FakeTwinAmiHandle;
    pbx3BindFakeTwinAmi($fake);

    $user = new User([
        'name' => 'Tenant User',
        'email' => 'tenantuser@example.com',
        'abilities' => ['tenant'],
        'allowed_clusters' => ['tenanta1'],
        'portable' => false,
    ]);
    $user->id = 2;
    Sanctum::actingAs($user, ['tenant']);

    $response = $this->putJson('/api/auth/astamis/DBput/srktwin/extb0001/ON');

    $response->assertStatus(403);
    expect($fake->putCalls)->toBe([]);
});

test('twin dbput rejects unsafe key characters', function () {
    $fake = new FakeTwinAmiHandle;
    pbx3BindFakeTwinAmi($fake);

    $user = new User([
        'name' => 'Tenant User',
        'email' => 'tenantuser@example.com',
        'abilities' => ['tenant'],
        'allowed_clusters' => ['tenanta1'],
        'portable' => false,
    ]);
    $user->id = 2;
    Sanctum::actingAs($user, ['tenant']);

    $response = $this->putJson('/api/auth/astamis/DBput/srktwin/'.rawurlencode('bad key;drop').'/ON');

    $response->assertStatus(422);
    expect($fake->putCalls)->toBe([]);
});

test('twin dbput calls PutDB with family srktwin only for allowlisted extension', function () {
    $fake = new FakeTwinAmiHandle;
    pbx3BindFakeTwinAmi($fake);

    $user = new User([
        'name' => 'Tenant User',
        'email' => 'tenantuser@example.com',
        'abilities' => ['tenant'],
        'allowed_clusters' => ['tenanta1'],
        'portable' => false,
    ]);
    $user->id = 2;
    Sanctum::actingAs($user, ['tenant']);

    $response = $this->putJson('/api/auth/astamis/DBput/srktwin/exta0001/441234567890');

    $response->assertOk();
    expect($fake->putCalls)->toBe([['srktwin', 'exta0001', '441234567890']])
        ->and($fake->delCalls)->toBe([]);
});

test('twin dbdel calls DelDB with family srktwin only for allowlisted extension', function () {
    $fake = new FakeTwinAmiHandle;
    pbx3BindFakeTwinAmi($fake);

    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'abilities' => ['admin'],
        'allowed_clusters' => null,
        'portable' => false,
    ]);
    $user->id = 1;
    Sanctum::actingAs($user, ['admin']);

    $response = $this->deleteJson('/api/auth/astamis/DBdel/srktwin/extb0001');

    $response->assertOk();
    expect($fake->delCalls)->toBe([['srktwin', 'extb0001']])
        ->and($fake->putCalls)->toBe([]);
});
