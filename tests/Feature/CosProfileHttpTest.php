<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $idpwgen = dirname(__DIR__, 2).'/../pbx3/pbx3-1/opt/pbx3/golang/idpwgen';
    if (is_executable($idpwgen)) {
        putenv('IDPWGEN_PATH='.$idpwgen);
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

    Schema::create('cos', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster')->nullable();
        $table->string('cname')->nullable();
    });

    Schema::create('cos_profile', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('cluster')->nullable();
        $table->string('cname')->nullable();
        $table->string('description')->nullable();
        $table->string('active')->default('YES');
        $table->string('is_default')->default('NO');
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    Schema::create('cos_profile_open', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster')->nullable();
        $table->string('active')->default('YES');
        $table->string('profile_pkey');
        $table->string('cos_pkey');
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    Schema::create('cos_profile_closed', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('cluster')->nullable();
        $table->string('active')->default('YES');
        $table->string('profile_pkey');
        $table->string('cos_pkey');
        $table->string('z_created')->nullable();
        $table->string('z_updated')->nullable();
        $table->string('z_updater')->nullable();
    });

    Schema::create('ipphone', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('shortuid')->nullable();
        $table->string('pkey');
        $table->string('active')->default('YES');
        $table->string('cluster')->nullable();
        $table->string('cos_profile')->nullable();
    });

    Schema::create('globals', function (Blueprint $table) {
        $table->string('mycommit')->nullable();
    });
    DB::table('globals')->insert(['mycommit' => 'NO']);

    DB::table('cluster')->insert([
        ['id' => 'tenantaksuid00000000000001', 'shortuid' => 'tenanta1', 'pkey' => 'TenantA'],
        ['id' => 'tenantbksuid00000000000001', 'shortuid' => 'tenantb1', 'pkey' => 'TenantB'],
    ]);

    DB::table('cos')->insert([
        ['id' => 'cosruleaksuid0000000000001', 'shortuid' => 'cosrula1', 'pkey' => 'Intl', 'cluster' => 'tenanta1', 'cname' => 'International'],
        ['id' => 'cosrulebksuid0000000000001', 'shortuid' => 'cosrulb1', 'pkey' => 'OtherRule', 'cluster' => 'tenantb1', 'cname' => 'Other'],
    ]);
});

function pbx3CosProfileTenantUser(array $allowedClusters): User
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

function pbx3CosProfileAdminUser(): User
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

test('tenant can create cos profile with rule lists for allowed cluster', function () {
    pbx3CosProfileTenantUser(['tenanta1']);

    $response = $this->postJson('/api/cosprofiles', [
        'cluster' => 'TenantA',
        'cname' => 'Staff',
        'description' => 'Day staff',
        'is_default' => 'YES',
        'open_rules' => ['Intl'],
        'closed_rules' => ['Intl'],
    ]);

    $response->assertSuccessful();
    $json = $response->json();
    expect($json['cname'])->toBe('Staff')
        ->and($json['cluster'])->toBe('tenanta1')
        ->and($json['is_default'])->toBe('YES')
        ->and($json['open_rules'])->toBe(['Intl'])
        ->and($json['closed_rules'])->toBe(['Intl'])
        ->and($json['pkey'])->not->toBeEmpty();

    expect(DB::table('cos_profile')->where('cluster', 'tenanta1')->count())->toBe(1);
    expect(DB::table('cos_profile_open')->where('cos_pkey', 'Intl')->count())->toBe(1);
});

test('tenant cannot create cos profile for other cluster', function () {
    pbx3CosProfileTenantUser(['tenanta1']);

    $response = $this->postJson('/api/cosprofiles', [
        'cluster' => 'TenantB',
        'cname' => 'Staff',
        'open_rules' => [],
        'closed_rules' => [],
    ]);

    $response->assertForbidden();
});

test('rejects unknown cos rule in open_rules', function () {
    pbx3CosProfileAdminUser();

    $response = $this->postJson('/api/cosprofiles', [
        'cluster' => 'TenantA',
        'cname' => 'Bad',
        'open_rules' => ['OtherRule'],
        'closed_rules' => [],
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('open_rules');
});

test('new profile is not default when tenant already has a default', function () {
    pbx3CosProfileAdminUser();

    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'profold1',
        'pkey' => 'profold1',
        'cluster' => 'tenanta1',
        'cname' => 'OldDefault',
        'active' => 'YES',
        'is_default' => 'YES',
    ]);

    $response = $this->postJson('/api/cosprofiles', [
        'cluster' => 'TenantA',
        'cname' => 'Staff',
        'is_default' => 'YES', // ignored — Q6 fixed Default
        'open_rules' => [],
        'closed_rules' => [],
    ]);

    $response->assertSuccessful();
    expect(DB::table('cos_profile')->where('cluster', 'tenanta1')->where('is_default', 'YES')->count())->toBe(1);
    expect(DB::table('cos_profile')->where('pkey', 'profold1')->value('is_default'))->toBe('YES');
    expect(DB::table('cos_profile')->where('cname', 'Staff')->value('is_default'))->toBe('NO');
});

test('cannot move is_default via update', function () {
    pbx3CosProfileAdminUser();

    DB::table('cos_profile')->insert([
        [
            'id' => 'profaksuid0000000000000001',
            'shortuid' => 'profold1',
            'pkey' => 'profold1',
            'cluster' => 'tenanta1',
            'cname' => 'Default',
            'active' => 'YES',
            'is_default' => 'YES',
        ],
        [
            'id' => 'profaksuid0000000000000002',
            'shortuid' => 'profnew1',
            'pkey' => 'profnew1',
            'cluster' => 'tenanta1',
            'cname' => 'Staff',
            'active' => 'YES',
            'is_default' => 'NO',
        ],
    ]);

    $response = $this->putJson('/api/cosprofiles/profnew1', [
        'is_default' => 'YES',
        'cname' => 'Staff',
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('is_default');
    expect(DB::table('cos_profile')->where('pkey', 'profold1')->value('is_default'))->toBe('YES');
    expect(DB::table('cos_profile')->where('pkey', 'profnew1')->value('is_default'))->toBe('NO');
});

test('index is cluster-scoped for tenant user', function () {
    pbx3CosProfileTenantUser(['tenanta1']);

    DB::table('cos_profile')->insert([
        [
            'id' => 'profaksuid0000000000000001',
            'shortuid' => 'profa001',
            'pkey' => 'profa001',
            'cluster' => 'tenanta1',
            'cname' => 'A',
            'active' => 'YES',
            'is_default' => 'YES',
        ],
        [
            'id' => 'profbksuid0000000000000001',
            'shortuid' => 'profb001',
            'pkey' => 'profb001',
            'cluster' => 'tenantb1',
            'cname' => 'B',
            'active' => 'YES',
            'is_default' => 'YES',
        ],
    ]);

    $response = $this->getJson('/api/cosprofiles');
    $response->assertOk();
    $rows = $response->json();
    expect($rows)->toHaveCount(1)->and($rows[0]['cname'])->toBe('A');
});

test('cannot delete default profile', function () {
    pbx3CosProfileAdminUser();

    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'profa001',
        'pkey' => 'profa001',
        'cluster' => 'tenanta1',
        'cname' => 'Default',
        'active' => 'YES',
        'is_default' => 'YES',
    ]);

    $response = $this->deleteJson('/api/cosprofiles/profa001');
    $response->assertStatus(409);
    expect(DB::table('cos_profile')->where('pkey', 'profa001')->exists())->toBeTrue();
});

test('cannot delete profile assigned to an extension', function () {
    pbx3CosProfileAdminUser();

    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'profa001',
        'pkey' => 'profa001',
        'cluster' => 'tenanta1',
        'cname' => 'Staff',
        'active' => 'YES',
        'is_default' => 'NO',
    ]);
    DB::table('ipphone')->insert([
        'id' => 'extaksuid0000000000000001',
        'shortuid' => 'exta0001',
        'pkey' => '1001',
        'active' => 'YES',
        'cluster' => 'tenanta1',
        'cos_profile' => 'profa001',
    ]);

    $response = $this->deleteJson('/api/cosprofiles/profa001');
    $response->assertStatus(409);
});

test('update replaces open and closed rule lists', function () {
    pbx3CosProfileAdminUser();

    DB::table('cos_profile')->insert([
        'id' => 'profaksuid0000000000000001',
        'shortuid' => 'profa001',
        'pkey' => 'profa001',
        'cluster' => 'tenanta1',
        'cname' => 'Staff',
        'active' => 'YES',
        'is_default' => 'NO',
    ]);
    DB::table('cos_profile_open')->insert([
        'id' => 'open0000000000000000000001',
        'cluster' => 'tenanta1',
        'active' => 'YES',
        'profile_pkey' => 'profa001',
        'cos_pkey' => 'Intl',
    ]);

    $response = $this->putJson('/api/cosprofiles/profa001', [
        'cname' => 'Lobby',
        'open_rules' => [],
        'closed_rules' => ['Intl'],
    ]);

    $response->assertOk();
    expect($response->json('cname'))->toBe('Lobby')
        ->and($response->json('open_rules'))->toBe([])
        ->and($response->json('closed_rules'))->toBe(['Intl']);
    expect(DB::table('cos_profile_open')->where('profile_pkey', 'profa001')->count())->toBe(0);
    expect(DB::table('cos_profile_closed')->where('profile_pkey', 'profa001')->count())->toBe(1);
});
