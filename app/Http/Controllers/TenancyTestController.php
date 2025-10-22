<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenancyTestController extends Controller
{
    /**
     * Show tenant information
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function info()
    {
        // Check if tenancy is initialized
        $initialized = app('tenancy')->initialized;
        
        if ($initialized) {
            $tenant = tenant();
            
            // Get some sample data from tenant database
            $tableCount = count(DB::connection('tenant')
                ->select("SELECT name FROM sqlite_master WHERE type='table'"));
                
            // Get extension count as an example
            $extensionCount = DB::connection('tenant')
                ->table('ipphone')
                ->count();
                
            return response()->json([
                'success' => true,
                'tenant' => [
                    'id' => $tenant->id,
                    'pkey' => $tenant->pkey,
                    'description' => $tenant->description,
                ],
                'database' => [
                    'connection' => 'tenant',
                    'tables' => $tableCount,
                    'extension_count' => $extensionCount,
                ],
                'message' => 'Tenant is properly initialized'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Tenancy not initialized. Make sure to include a tenant identifier in your request.'
            ], 400);
        }
    }
}