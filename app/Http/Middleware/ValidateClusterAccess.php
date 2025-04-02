<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\Helper;

class ValidateClusterAccess
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('cluster')) {
            // Get the user's token abilities from the helper
            $abilities = Helper::getTokenAbilities($request->bearerToken());
            
            // Check if the user has access to this cluster
            if (!in_array('cluster:' . $request->cluster, $abilities)) {
                return response()->json([
                    'error' => 'Unauthorized cluster access',
                    'message' => 'You do not have permission to access this cluster'
                ], 403);
            }
        }
        
        return $next($request);
    }
}
