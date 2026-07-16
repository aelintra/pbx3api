<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** POST ops events to fleet Gatekeeper (misconfig REGISTER loops). */
final class GatekeeperOpsClient
{
    public function isConfigured(): bool
    {
        $base = config('pbx3_ops.gatekeeper_url');
        $token = config('pbx3_ops.gatekeeper_token');

        return is_string($base) && trim($base) !== ''
            && is_string($token) && trim($token) !== '';
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{accepted: bool, notified?: bool, reason?: string}
     */
    public function postEvent(array $event): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper ops client not configured');
        }

        $base = rtrim((string) config('pbx3_ops.gatekeeper_url'), '/');
        $verify = (bool) config('pbx3_ops.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_ops.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(20)
            ->post("{$base}/api/v1/ops-events", $event);

        if (! $response->successful()) {
            Log::warning('gatekeeper ops-events failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException(
                'Gatekeeper ops-events failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : ['accepted' => true];
    }
}
