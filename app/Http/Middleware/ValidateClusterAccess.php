<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateClusterAccess
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('cluster')) {
            $user = $request->user('sanctum');

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Authentication required'
                ], 401);
            }

            if (!$user->tokenCan('cluster:' . $request->cluster)) {
                return response()->json([
                    'error' => 'Unauthorized cluster access',
                    'message' => 'You do not have permission to access this cluster'
                ], 403);
            }
        }

        return $next($request);
    }
}
