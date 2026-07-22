<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Services\Recordings\GatekeeperRecordingsClient;
use App\Services\Recordings\RecordingIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call recordings management (Phase R1 / R1.5 / S7).
 *
 * List/search via SQLite catalog when present, with spool filesystem fallback.
 * Stream/download resolve local paths first; S3-only rows use gatekeeper GET
 * presign then server-side proxy (keeps recordings bucket CORS-free).
 */
class RecordingController extends Controller
{
    use EnforcesClusterScope;

    public function __construct(
        private readonly RecordingIndexService $index,
        private readonly GatekeeperRecordingsClient $gatekeeper,
    ) {}

    /**
     * List/search recordings. Filters: tenant, from, to (YYYY-MM-DD UTC or epoch),
     * search (caller/callee/queue/extension/filename).
     * Non-admin: tenant clamped to allowed_clusters.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant' => 'sometimes|nullable|string',
            'from' => 'sometimes|nullable|string',
            'to' => 'sometimes|nullable|string',
            'search' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $filters = [
            'from' => $this->toEpoch($request->input('from'), false),
            'to' => $this->toEpoch($request->input('to'), true),
            'search' => $request->filled('search') ? (string) $request->input('search') : null,
        ];

        $scope = $this->clampedClusterScopeOrNull();
        $requested = $request->filled('tenant') ? (string) $request->input('tenant') : null;

        if ($scope === null) {
            $filters['tenant'] = $requested;
        } elseif ($requested !== null && $requested !== '') {
            $this->assertRequestedClusterInScope($requested);
            $filters['tenant'] = $requested;
        } else {
            $filters['tenants'] = $scope;
        }

        return response()->json($this->index->list($filters), 200);
    }

    /** Stream a recording inline (supports range requests for local files). */
    public function stream(string $recording)
    {
        $this->assertRecordingClusterAllowed($recording);

        $abs = $this->index->absolutePathFromId($recording);
        if ($abs !== null && is_file($abs)) {
            return response()->file($abs, [
                'Content-Type' => 'audio/wav',
                'Accept-Ranges' => 'bytes',
            ]);
        }

        return $this->streamFromS3($recording, false);
    }

    /** Download a recording as an attachment. */
    public function download(string $recording)
    {
        $this->assertRecordingClusterAllowed($recording);

        $abs = $this->index->absolutePathFromId($recording);
        if ($abs !== null && is_file($abs)) {
            return response()->download($abs, basename($abs), [
                'Content-Type' => 'audio/wav',
            ]);
        }

        return $this->streamFromS3($recording, true);
    }

    private function assertRecordingClusterAllowed(string $recording): void
    {
        $cluster = $this->index->clusterFromId($recording);
        if ($cluster === null) {
            // Unknown id → let stream/download return 404 downstream
            return;
        }
        $this->assertClusterAllowed($cluster);
    }

    /**
     * Proxy a gated S3 GET so the browser never talks to the recordings bucket.
     */
    private function streamFromS3(string $recording, bool $asAttachment): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $key = $this->index->s3KeyFromId($recording);
        if ($key === null) {
            return Response::json(['Error' => 'Recording not found'], 404);
        }

        if (! $this->gatekeeper->isConfigured()) {
            return Response::json(['Error' => 'Recording archived remotely but gatekeeper is not configured'], 503);
        }

        try {
            $presign = $this->gatekeeper->presign('GET', $key);
            $verify = (bool) config('pbx3_recordings.gatekeeper_http_verify', true);
            $upstream = Http::withOptions(['verify' => $verify, 'stream' => true])
                ->timeout(120)
                ->get($presign['url']);

            if (! $upstream->successful()) {
                Log::warning('recording s3 GET failed', [
                    'key' => $key,
                    'status' => $upstream->status(),
                ]);

                return Response::json(['Error' => 'Recording not found in archive'], 404);
            }
        } catch (\Throwable $e) {
            Log::warning('recording s3 stream exception', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return Response::json(['Error' => 'Recording archive unavailable'], 503);
        }

        $filename = $this->index->filenameFromId($recording) ?? basename($key);
        $headers = [
            'Content-Type' => 'audio/wav',
            'Accept-Ranges' => 'bytes',
        ];
        if ($asAttachment) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        } else {
            $headers['Content-Disposition'] = 'inline; filename="'.$filename.'"';
        }

        return response()->stream(function () use ($upstream) {
            $body = $upstream->toPsrResponse()->getBody();
            while (! $body->eof()) {
                echo $body->read(8192);
                if (connection_aborted()) {
                    break;
                }
            }
        }, 200, $headers);
    }

    /**
     * Normalise a from/to input to a UTC epoch. Accepts a bare epoch or a
     * YYYY-MM-DD date; for the "to" bound the whole day is included.
     */
    private function toEpoch(mixed $value, bool $endOfDay): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $suffix = $endOfDay ? ' 23:59:59' : ' 00:00:00';
        $ts = strtotime($value.$suffix.' UTC');

        return $ts === false ? null : $ts;
    }
}
