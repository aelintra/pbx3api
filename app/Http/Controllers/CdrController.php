<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Services\Cdr\CdrIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CdrController extends Controller
{
    use EnforcesClusterScope;

    /**
     * Phase 6: searchable Asterisk CDR from master.db (not logs/cdrs).
     * Non-admin: accountcode clamped to allowed_clusters (IN list).
     */
    public function index(Request $request, CdrIndexService $index)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'sometimes|string|max:32',
            'to' => 'sometimes|string|max:32',
            'search' => 'sometimes|string|max:128',
            'accountcode' => 'sometimes|string|max:64',
            'disposition' => 'sometimes|string|max:32',
            'limit' => 'sometimes|integer|min:1|max:500',
            'offset' => 'sometimes|integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $filters = [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'search' => $request->input('search'),
            'disposition' => $request->input('disposition'),
            'limit' => $request->input('limit'),
            'offset' => $request->input('offset'),
        ];

        $scope = $this->clampedClusterScopeOrNull();
        $requested = $request->filled('accountcode') ? (string) $request->input('accountcode') : null;

        if ($scope === null) {
            $filters['accountcode'] = $requested;
        } else {
            if ($scope === []) {
                $filters['accountcodes'] = ['__none__'];
            } elseif ($requested !== null && $requested !== '') {
                $this->assertRequestedClusterInScope($requested);
                $filters['accountcode'] = $requested;
            } else {
                $filters['accountcodes'] = $scope;
            }
        }

        try {
            return response()->json($index->list($filters), 200);
        } catch (\Throwable $e) {
            Log::error('cdr list failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
