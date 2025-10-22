<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

// API Routes for tenant-specific operations
// These routes will be available at tenant subdomains, for example: cluster1.domain.com/api/...

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api')->group(function () {
    // All API routes that should be tenant-aware can be included here
    // These will be mounted for each tenant separately
    Route::get('/', function () {
        return response()->json([
            'message' => 'Welcome to the PBX3 API',
            'tenant' => tenant('pkey'),
            'description' => tenant('description')
        ]);
    });

    // Tenant routes can be included here or you can include the api.php file
    // Route::middleware('auth:sanctum')->include('api.php');
});
