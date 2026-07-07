<?php

namespace App\Http\Controllers;

use App\Services\Recordings\RecordingIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Call recordings management (Phase R1 — local-first).
 *
 * Read-only over the recordings disk (config: filesystems 'recordings',
 * PBX3_RECORDINGS_ROOT). No DB table; the filesystem is the source of truth.
 * Tenant scoping is by directory; access is admin-only (route middleware).
 */
class RecordingController extends Controller
{
    public function __construct(private readonly RecordingIndexService $index)
    {
    }

    /**
     * List/search recordings. Filters: tenant, from, to (YYYY-MM-DD UTC or epoch),
     * search (caller/callee/queue/extension/filename).
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
            'tenant' => $request->filled('tenant') ? (string) $request->input('tenant') : null,
            'from' => $this->toEpoch($request->input('from'), false),
            'to' => $this->toEpoch($request->input('to'), true),
            'search' => $request->filled('search') ? (string) $request->input('search') : null,
        ];

        return response()->json($this->index->list($filters), 200);
    }

    /** Stream a recording inline (supports range requests for seeking). */
    public function stream(string $recording)
    {
        $rel = $this->index->relativePathFromId($recording);
        if ($rel === null) {
            return Response::json(['Error' => 'Recording not found'], 404);
        }

        return response()->file($this->index->absolutePath($rel), [
            'Content-Type' => 'audio/wav',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** Download a recording as an attachment. */
    public function download(string $recording)
    {
        $rel = $this->index->relativePathFromId($recording);
        if ($rel === null) {
            return Response::json(['Error' => 'Recording not found'], 404);
        }

        return response()->download($this->index->absolutePath($rel), basename($rel), [
            'Content-Type' => 'audio/wav',
        ]);
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
