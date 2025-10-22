<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantFromClusterHeader
{
    protected $resolver;
    
    public function __construct(RequestDataTenantResolver $resolver)
    {
        $this->resolver = $resolver;
    }
    
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if a cluster header is provided
        $cluster = $request->header('X-Cluster') ?? $request->query('cluster');
        
        if ($cluster) {
            // Set the tenant ID in the tenant resolver
            $this->resolver->resolve($cluster);
        }
        
        return $next($request);
    }
}