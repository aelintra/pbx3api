<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client: request short-lived recordings PUT/GET URLs from the fleet gatekeeper (S7).
 * Nodes never hold blanket tenants/* IAM — only these presigns.
 */
class GatekeeperRecordingsClient
{
    public function isConfigured(): bool
    {
        $base = config('pbx3_recordings.gatekeeper_url');
        $token = config('pbx3_recordings.gatekeeper_token');

        return is_string($base) && trim($base) !== ''
            && is_string($token) && trim($token) !== '';
    }

    /**
     * @return array{url: string, method: string, key: string, expires_in: int, bucket: string}
     */
    public function presign(string $method, string $key, ?string $tagging = null, ?int $expiresIn = null): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper recordings client not configured');
        }

        $base = rtrim((string) config('pbx3_recordings.gatekeeper_url'), '/');
        $ttl = $expiresIn ?? (int) config('pbx3_recordings.presign_ttl_seconds', 900);

        $body = [
            'method' => strtoupper($method),
            'key' => $key,
            'expires_in' => $ttl,
        ];
        if ($tagging !== null && $tagging !== '') {
            $body['tagging'] = $tagging;
        }

        $verify = (bool) config('pbx3_recordings.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_recordings.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(30)
            ->post("{$base}/api/v1/s3/presign-recordings", $body);

        if (! $response->successful()) {
            Log::warning('gatekeeper recordings presign failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'key' => $key,
            ]);
            throw new \RuntimeException(
                'Gatekeeper presign-recordings failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['url'])) {
            throw new \RuntimeException('Gatekeeper presign-recordings: missing url in response');
        }

        return [
            'url' => (string) $json['url'],
            'method' => (string) ($json['method'] ?? $method),
            'key' => (string) ($json['key'] ?? $key),
            'expires_in' => (int) ($json['expires_in'] ?? $ttl),
            'bucket' => (string) ($json['bucket'] ?? ''),
        ];
    }
}
