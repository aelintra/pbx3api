<?php

use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);
    config(['database.connections.sqlite.prefix' => '']);
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\DB::purge('sqlite');
    \Illuminate\Support\Facades\DB::reconnect('sqlite');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->json('abilities')->nullable();
        $table->json('allowed_clusters')->nullable();
        $table->boolean('portable')->default(true);
        $table->string('endpoint')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('personal_access_tokens');
    Schema::dropIfExists('users');
});

function makeAdminUser(string $password = 'password'): User
{
    return User::query()->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make($password),
        'abilities' => ['admin'],
        'portable' => false,
    ]);
}

test('login without 2FA returns accessToken', function () {
    makeAdminUser();

    $response = $this->postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['accessToken', 'token_type'])
        ->assertJsonMissing(['requires_2fa']);
});

test('login with 2FA requires challenge then verify', function () {
    $totp = app(TotpService::class);
    $user = makeAdminUser();
    $plain = $totp->generateSecret();
    $user->two_factor_secret = $totp->encryptSecret($plain);
    $user->two_factor_confirmed_at = now();
    $user->two_factor_recovery_codes = $totp->hashRecoveryCodes(['AAAA-BBBB']);
    $user->save();

    $login = $this->postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $login->assertOk()
        ->assertJsonPath('requires_2fa', true)
        ->assertJsonStructure(['challenge_id'])
        ->assertJsonMissing(['accessToken']);

    $challengeId = $login->json('challenge_id');
    $code = (new Google2FA)->getCurrentOtp($plain);

    $bad = $this->postJson('/api/auth/2fa/verify', [
        'challenge_id' => $challengeId,
        'code' => '000000',
    ]);
    $bad->assertUnauthorized();

    $ok = $this->postJson('/api/auth/2fa/verify', [
        'challenge_id' => $challengeId,
        'code' => $code,
    ]);
    $ok->assertOk()->assertJsonStructure(['accessToken', 'token_type']);
});

test('recovery code completes login and is single-use', function () {
    $totp = app(TotpService::class);
    $user = makeAdminUser();
    $plain = $totp->generateSecret();
    $user->two_factor_secret = $totp->encryptSecret($plain);
    $user->two_factor_confirmed_at = now();
    $user->two_factor_recovery_codes = $totp->hashRecoveryCodes(['RECO-VERY1']);
    $user->save();

    $login = $this->postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);
    $challengeId = $login->json('challenge_id');

    $this->postJson('/api/auth/2fa/verify', [
        'challenge_id' => $challengeId,
        'code' => 'RECO-VERY1',
    ])->assertOk()->assertJsonStructure(['accessToken']);

    $login2 = $this->postJson('/api/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);
    $this->postJson('/api/auth/2fa/verify', [
        'challenge_id' => $login2->json('challenge_id'),
        'code' => 'RECO-VERY1',
    ])->assertUnauthorized();
});

test('enroll confirm and disable 2FA', function () {
    $user = makeAdminUser();
    Sanctum::actingAs($user, ['admin']);

    $setup = $this->postJson('/api/auth/2fa/setup', ['password' => 'password']);
    $setup->assertOk()->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg', 'issuer']);

    $secret = $setup->json('secret');
    $code = (new Google2FA)->getCurrentOtp($secret);

    $confirm = $this->postJson('/api/auth/2fa/confirm', ['code' => $code]);
    $confirm->assertOk()
        ->assertJsonPath('two_factor_enabled', true)
        ->assertJsonStructure(['recovery_codes']);

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue();

    $disableCode = (new Google2FA)->getCurrentOtp($secret);
    $this->postJson('/api/auth/2fa/disable', [
        'password' => 'password',
        'code' => $disableCode,
    ])->assertOk()->assertJsonPath('two_factor_enabled', false);

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

test('admin can clear another users 2FA', function () {
    $totp = app(TotpService::class);
    $admin = makeAdminUser();
    $other = User::query()->create([
        'name' => 'Other',
        'email' => 'other@example.com',
        'password' => Hash::make('password'),
        'abilities' => ['tenant'],
        'allowed_clusters' => ['dhbm8x'],
        'portable' => true,
    ]);
    $plain = $totp->generateSecret();
    $other->two_factor_secret = $totp->encryptSecret($plain);
    $other->two_factor_confirmed_at = now();
    $other->save();

    Sanctum::actingAs($admin, ['admin']);

    $this->deleteJson('/api/auth/users/'.$other->id.'/2fa')
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    $other->refresh();
    expect($other->hasTwoFactorEnabled())->toBeFalse();
});
