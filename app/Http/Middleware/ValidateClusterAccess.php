<?php

namespace App\Http\Middleware;

use App\Support\ClusterAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * When the request includes a cluster identifier, ensure the Sanctum user may access it.
 * Admins bypass; non-admins must have the cluster in users.allowed_clusters.
 */
class ValidateClusterAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->has('cluster') && ! $request->route('cluster')) {
            return $next($request);
        }

        $cluster = $request->input('cluster') ?? $request->route('cluster');
        if ($cluster === null || $cluster === '') {
            return $next($request);
        }

        $user = $request->user('sanctum');

        if (! $user) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required',
            ], 401);
        }

        if (! ClusterAccess::userMayAccessCluster($user, (string) $cluster)) {
            return response()->json([
                'error' => 'Unauthorized cluster access',
                'message' => 'You do not have permission to access this cluster',
            ], 403);
        }

        return $next($request);
    }
}
