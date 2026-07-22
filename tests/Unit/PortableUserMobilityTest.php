<?php

uses(Tests\TestCase::class);

use App\Models\User;
use App\Services\Tenant\PortableUserMobility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    config(['database.connections.sqlite.prefix' => '']);
    \Illuminate\Support\Facades\DB::purge('sqlite');
    \Illuminate\Support\Facades\DB::reconnect('sqlite');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->json('abilities')->nullable();
        $table->json('allowed_clusters')->nullable();
        $table->boolean('portable')->default(true);
        $table->string('endpoint')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('users');
});

test('collectForTenant packs only portable non-admin users for that shortuid', function () {
    $hash = Hash::make('Secret1!');
    User::query()->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => $hash,
        'abilities' => ['admin'],
        'allowed_clusters' => null,
        'portable' => false,
    ]);
    User::query()->create([
        'name' => 'Tenant A',
        'email' => 'a@example.com',
        'password' => $hash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['dhbm8x'],
        'portable' => true,
    ]);
    User::query()->create([
        'name' => 'Other',
        'email' => 'b@example.com',
        'password' => $hash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['other01'],
        'portable' => true,
    ]);
    User::query()->create([
        'name' => 'Multi',
        'email' => 'm@example.com',
        'password' => $hash,
        'abilities' => ['tenant', 'recordings'],
        'allowed_clusters' => ['dhbm8x', 'other01'],
        'portable' => true,
    ]);

    $svc = new PortableUserMobility;
    $rows = $svc->collectForTenant('dhbm8x');

    expect($rows)->toHaveCount(2);
    $emails = array_column($rows, 'email');
    expect($emails)->toContain('a@example.com')->toContain('m@example.com')
        ->and($emails)->not->toContain('admin@example.com')
        ->and($emails)->not->toContain('b@example.com');

    foreach ($rows as $row) {
        expect($row['allowed_clusters'])->toBe(['dhbm8x'])
            ->and($row['portable'])->toBeTrue()
            ->and($row['password'])->not->toBe('')
            ->and($row['abilities'])->not->toContain('admin');
    }
});

test('detach deletes single-cluster and strips multi-cluster', function () {
    $hash = Hash::make('Secret1!');
    User::query()->create([
        'name' => 'Single',
        'email' => 'single@example.com',
        'password' => $hash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['dhbm8x'],
        'portable' => true,
    ]);
    User::query()->create([
        'name' => 'Multi',
        'email' => 'multi@example.com',
        'password' => $hash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['dhbm8x', 'keep01'],
        'portable' => true,
    ]);

    $result = (new PortableUserMobility)->detachFromSource('dhbm8x');

    expect($result)->toBe(['deleted' => 1, 'stripped' => 1])
        ->and(User::query()->where('email', 'single@example.com')->exists())->toBeFalse();

    $multi = User::query()->where('email', 'multi@example.com')->first();
    expect($multi)->not->toBeNull()
        ->and($multi->allowed_clusters)->toBe(['keep01']);
});

test('import creates user and skips admin email conflict', function () {
    $hash = Hash::make('Secret1!');
    User::query()->create([
        'name' => 'Admin',
        'email' => 'taken@example.com',
        'password' => $hash,
        'abilities' => ['admin'],
        'portable' => false,
    ]);

    $svc = new PortableUserMobility;
    $result = $svc->importUsers([
        [
            'email' => 'taken@example.com',
            'name' => 'Customer',
            'password' => $hash,
            'abilities' => ['tenant'],
            'allowed_clusters' => ['dhbm8x'],
            'portable' => true,
        ],
        [
            'email' => 'new@example.com',
            'name' => 'New Customer',
            'password' => $hash,
            'abilities' => ['tenant', 'recordings'],
            'allowed_clusters' => ['dhbm8x'],
            'portable' => true,
        ],
    ], 'dhbm8x');

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toContain('instance admin');

    $new = User::query()->where('email', 'new@example.com')->first();
    expect($new)->not->toBeNull()
        ->and($new->portable)->toBeTrue()
        ->and($new->abilities)->toBe(['tenant', 'recordings'])
        ->and($new->allowed_clusters)->toBe(['dhbm8x'])
        ->and(Hash::check('Secret1!', $new->password))->toBeTrue();
});

test('import updates existing portable user and merges cluster', function () {
    $oldHash = Hash::make('OldPass1!');
    $newHash = Hash::make('NewPass1!');
    User::query()->create([
        'name' => 'Existing',
        'email' => 'cust@example.com',
        'password' => $oldHash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['keep01'],
        'portable' => true,
    ]);

    $result = (new PortableUserMobility)->importUsers([
        [
            'email' => 'cust@example.com',
            'name' => 'Renamed',
            'password' => $newHash,
            'abilities' => ['tenant', 'recordings'],
            'allowed_clusters' => ['dhbm8x'],
            'portable' => true,
        ],
    ], 'dhbm8x');

    expect($result['updated'])->toBe(1)->and($result['created'])->toBe(0);

    $user = User::query()->where('email', 'cust@example.com')->first();
    expect($user->name)->toBe('Renamed')
        ->and($user->abilities)->toBe(['tenant', 'recordings'])
        ->and($user->allowed_clusters)->toContain('dhbm8x')
        ->and($user->allowed_clusters)->toContain('keep01')
        ->and(Hash::check('NewPass1!', $user->password))->toBeTrue();
});

test('writeExportFile round-trips via importFromWorkDir', function () {
    $hash = Hash::make('Secret1!');
    User::query()->create([
        'name' => 'Pack',
        'email' => 'pack@example.com',
        'password' => $hash,
        'abilities' => ['tenant'],
        'allowed_clusters' => ['dhbm8x'],
        'portable' => true,
    ]);

    $svc = new PortableUserMobility;
    $work = sys_get_temp_dir().'/pbx3-pu-'.bin2hex(random_bytes(4));
    mkdir($work, 0700, true);
    try {
        $rows = $svc->collectForTenant('dhbm8x');
        $svc->writeExportFile($work, 'dhbm8x', $rows);
        User::query()->where('email', 'pack@example.com')->delete();

        $result = $svc->importFromWorkDir($work, 'dhbm8x');
        expect($result['created'])->toBe(1)
            ->and(User::query()->where('email', 'pack@example.com')->exists())->toBeTrue();
    } finally {
        @unlink($work.'/'.PortableUserMobility::JSON_FILENAME);
        @rmdir($work);
    }
});
