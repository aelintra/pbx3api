<?php

use App\Models\User;
use App\Services\Recordings\RecordingIndexService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'abilities' => ['admin'],
    ]);
    $user->id = 1;
    Sanctum::actingAs($user, ['admin']);
});

test('recordings index returns mocked list', function () {
    $this->mock(RecordingIndexService::class, function ($mock) {
        $mock->shouldReceive('list')
            ->once()
            ->andReturn([
                [
                    'id' => 'legacy:test',
                    'tenant' => '9wvvnb',
                    'filename' => 'call.wav',
                ],
            ]);
    });

    $response = $this->getJson('/api/recordings');

    $response->assertOk()
        ->assertJsonPath('0.tenant', '9wvvnb')
        ->assertJsonPath('0.filename', 'call.wav');
});

test('recordings index passes from to tenant search filters and lists s3_only rows', function () {
    $from = strtotime('2024-05-19 00:00:00 UTC');
    $to = strtotime('2024-05-19 23:59:59 UTC');

    $this->mock(RecordingIndexService::class, function ($mock) use ($from, $to) {
        $mock->shouldReceive('list')
            ->once()
            ->with(\Mockery::on(function (array $filters) use ($from, $to) {
                return ($filters['from'] ?? null) === $from
                    && ($filters['to'] ?? null) === $to
                    && ($filters['tenant'] ?? null) === '9wvvnb'
                    && ($filters['search'] ?? null) === '555';
            }))
            ->andReturn([
                [
                    'id' => '0o5Fs0EELNVK5ZMKO0XLVZbnjGx',
                    'tenant' => '9wvvnb',
                    'filename' => '1716123456-9wvvnb-5551234-5559876.wav',
                    'epoch' => 1716123456,
                    'location' => 's3_only',
                    'on_s3' => true,
                    'storage' => 's3_only',
                    'archived' => true,
                    'playable' => true,
                ],
            ]);
    });

    $response = $this->getJson(
        '/api/recordings?from=2024-05-19&to=2024-05-19&tenant=9wvvnb&search=555'
    );

    $response->assertOk()
        ->assertJsonPath('0.location', 's3_only')
        ->assertJsonPath('0.archived', true)
        ->assertJsonPath('0.tenant', '9wvvnb');
});

test('recordings stream returns 404 when missing', function () {
    $this->mock(RecordingIndexService::class, function ($mock) {
        // clusterFromId runs first (ACL); null = unknown id → 404 downstream
        $mock->shouldReceive('clusterFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
        $mock->shouldReceive('absolutePathFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
        $mock->shouldReceive('s3KeyFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
    });

    $response = $this->getJson('/api/recordings/missing-id/stream');

    $response->assertNotFound()
        ->assertJson(['Error' => 'Recording not found']);
});

test('recordings download returns 404 when missing', function () {
    $this->mock(RecordingIndexService::class, function ($mock) {
        $mock->shouldReceive('clusterFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
        $mock->shouldReceive('absolutePathFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
        $mock->shouldReceive('s3KeyFromId')
            ->once()
            ->with('missing-id')
            ->andReturn(null);
    });

    $response = $this->getJson('/api/recordings/missing-id/download');

    $response->assertNotFound()
        ->assertJson(['Error' => 'Recording not found']);
});
