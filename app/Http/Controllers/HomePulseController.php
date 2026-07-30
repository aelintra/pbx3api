<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Services\HomePulseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HomePulseController extends Controller
{
    use EnforcesClusterScope;

    /**
     * GET /home/pulse — Instance Home ops pulse (composable blocks).
     *
     * Query: include=system,live,cdr (default all).
     * Non-admin: CDR + endpoints respect allowed_clusters.
     */
    public function show(Request $request, HomePulseService $pulse)
    {
        $validator = Validator::make($request->all(), [
            'include' => 'sometimes|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $include = HomePulseService::parseInclude($request->input('include'));

        $cdrFilters = [];
        $scope = $this->clampedClusterScopeOrNull();
        if ($scope === []) {
            $cdrFilters['accountcodes'] = ['__none__'];
        } elseif ($scope !== null) {
            $cdrFilters['accountcodes'] = $scope;
        }

        try {
            return response()->json(
                $pulse->pulse($include, $cdrFilters, $scope),
                200
            );
        } catch (\Throwable $e) {
            Log::error('home pulse failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Home pulse unavailable'], 500);
        }
    }
}
