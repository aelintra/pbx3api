<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Node → Gatekeeper catalog writes (sitename ≡ label). Rule 9/10: via Gatekeeper, not browser IAM.
 *
 * @see pbx3/workingdocs/FLEET_NAMING_LOCK.md
 */
class GatekeeperCatalogClient
{
    public function isConfigured(): bool
    {
        $base = config('pbx3_fleet.gatekeeper_url');
        $token = config('pbx3_fleet.gatekeeper_token');

        return is_string($base) && trim($base) !== ''
            && is_string($token) && trim($token) !== '';
    }

    /**
     * PATCH /api/v1/instances/{id} — at least label.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function patchInstance(string $instanceId, array $patch): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Gatekeeper catalog client not configured');
        }

        $id = trim($instanceId);
        if ($id === '') {
            throw new \InvalidArgumentException('Instance id required');
        }

        $base = rtrim((string) config('pbx3_fleet.gatekeeper_url'), '/');
        $verify = (bool) config('pbx3_fleet.gatekeeper_http_verify', true);

        $response = Http::withToken((string) config('pbx3_fleet.gatekeeper_token'))
            ->acceptJson()
            ->withOptions(['verify' => $verify])
            ->timeout(20)
            ->patch("{$base}/api/v1/instances/".rawurlencode($id), $patch);

        if (! $response->successful()) {
            Log::warning('gatekeeper catalog patch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'instance_id' => $id,
            ]);
            throw new \RuntimeException(
                'Gatekeeper catalog update failed: HTTP '.$response->status(),
                $response->status()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
