<?php

use App\Models\User;
use App\Services\Recordings\RecordingIndexService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $user = new User([
        'name' => 'Admin',
        'email' => 'admin@example.com',
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

test('recordings stream returns 404 when missing', function () {
    $this->mock(RecordingIndexService::class, function ($mock) {
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
