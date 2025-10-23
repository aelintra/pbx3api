<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

class ValidateClusterAccess
{
    protected $tenancy;
    
    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }
    
    public function handle(Request $request, Closure $next)
    {
        // Get the cluster/tenant from the request
        $cluster = $request->header('X-Cluster') ?? $request->query('cluster');
        
        if ($cluster) {
            // Get the user's token abilities from the helper
            // Get the user's token abilities from the helper (guard if Helper class is absent)
            $abilities = [];
            $helperClass = 'App\\Helpers\\Helper';
            if (class_exists($helperClass) && is_callable([$helperClass, 'getTokenAbilities'])) {
                $abilities = call_user_func([$helperClass, 'getTokenAbilities'], $request->bearerToken());
            }
            // Check if the user has access to this cluster
            if (!in_array('cluster:' . $cluster, $abilities)) {
                return response()->json([
                    'error' => 'Unauthorized cluster access',
                    'message' => 'You do not have permission to access this cluster'
                ], 403);
            }
            
            // If tenant isn't already initialized, initialize it
            if (!$this->tenancy->initialized && $this->tenancy->getTenant() === null) {
                $tenant = app(\App\Models\MultiTenant::class)::where('pkey', $cluster)->first();
                
                if ($tenant) {
                    $this->tenancy->initialize($tenant);
                } else {
                    return response()->json([
                        'error' => 'Invalid cluster',
                        'message' => 'The requested cluster does not exist'
                    ], 404);
                }
            }
        }
        
        return $next($request);
    }
}
