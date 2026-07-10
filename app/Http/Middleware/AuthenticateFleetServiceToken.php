<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fleet control-plane → node auth (S8.10). Shared bearer, not Sanctum admin.
 * Env: PBX3_FLEET_SERVICE_TOKEN
 */
class AuthenticateFleetServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('pbx3_fleet.service_token', '');
        if ($expected === '') {
            return response()->json([
                'message' => 'Fleet service token not configured (PBX3_FLEET_SERVICE_TOKEN)',
            ], 503);
        }

        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! hash_equals($expected, trim($m[1]))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
