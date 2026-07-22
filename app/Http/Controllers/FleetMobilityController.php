<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Directory\FleetPreflightService;
use App\Services\Tenant\TenantMobilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Node-side mobility HTTP API for the fleet gatekeeper (S8.10 §13.3.1).
 * Auth: fleet service bearer — not Sanctum admin.
 */
class FleetMobilityController extends Controller
{
    public function preflight(FleetPreflightService $preflight): JsonResponse
    {
        $checks = $preflight->run();

        return response()->json([
            'ok' => $preflight->allPassed(),
            'checks' => $checks,
        ]);
    }

    /** Gatekeeper fleet probe — AMI Egress qualify (Avail/Unavail). */
    public function egressQualify(\App\Services\Fleet\FleetPostureService $posture): JsonResponse
    {
        $live = $posture->egressQualifyLive();

        return response()->json([
            'fleet' => $posture->isFleetNode(),
            'egress_trunk' => (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress'),
            'state' => (string) ($live['state'] ?? 'Unknown'),
            'rtt_ms' => $live['rtt_ms'] ?? null,
            'latency' => $live['latency'] ?? null,
        ]);
    }

    public function export(Request $request, string $tenant, TenantMobilityService $mobility): JsonResponse
    {
        $presignedUrl = (string) $request->input('presigned_put_url', '');
        if ($presignedUrl === '') {
            return response()->json(['message' => 'presigned_put_url is required'], 422);
        }

        try {
            $result = $mobility->export($tenant, [
                'include_recordings' => (bool) $request->boolean('include_recordings'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $zipPath = $result['zip_path'];
        try {
            $this->uploadFile($presignedUrl, $zipPath);
        } catch (\Throwable $e) {
            Log::error('fleet export upload failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Export created but upload to staging failed: '.$e->getMessage(),
                'zip_path' => $zipPath,
                'manifest' => $result['manifest'],
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'manifest' => $result['manifest'],
            'uploaded' => true,
        ]);
    }

    public function import(Request $request, TenantMobilityService $mobility): JsonResponse
    {
        $presignedUrl = (string) $request->input('presigned_get_url', '');
        if ($presignedUrl === '') {
            return response()->json(['message' => 'presigned_get_url is required'], 422);
        }

        $tmp = sys_get_temp_dir().'/pbx3fleet-import-'.bin2hex(random_bytes(4)).'.zip';
        try {
            $this->downloadFile($presignedUrl, $tmp);
            $result = $mobility->import($tmp, [
                'replace' => (bool) $request->boolean('replace'),
                'skip_media' => (bool) $request->boolean('skip_media'),
            ]);
        } catch (\Throwable $e) {
            @unlink($tmp);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        @unlink($tmp);

        return response()->json([
            'ok' => true,
            'result' => $result,
        ]);
    }

    public function commit(SysCommandController $sys)
    {
        return $sys->commit();
    }

    public function certificatesSync(Request $request, CertificateController $certs)
    {
        return $certs->sync($request);
    }

    public function destroyTenant(string $tenant): JsonResponse
    {
        $model = (new Tenant)->resolveRouteBinding($tenant);
        if ($model === null) {
            return response()->json(['message' => "Tenant not found: {$tenant}"], 404);
        }
        if ($model->pkey === 'default') {
            return response()->json(['message' => 'Cannot delete default tenant'], 409);
        }

        $id = $model->id;
        $model->delete();
        pbx3_update_fqdn_inline_optional();

        return response()->json(['ok' => true, 'deleted' => $id]);
    }

    private function uploadFile(string $url, string $path): void
    {
        $body = file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException("Cannot read export zip: {$path}");
        }

        $response = Http::withBody($body, 'application/zip')
            ->timeout(300)
            ->put($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Presigned PUT failed HTTP '.$response->status());
        }
    }

    private function downloadFile(string $url, string $destPath): void
    {
        $response = Http::timeout(300)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Presigned GET failed HTTP '.$response->status());
        }
        if (file_put_contents($destPath, $response->body()) === false) {
            throw new \RuntimeException("Cannot write import zip: {$destPath}");
        }
    }
}
