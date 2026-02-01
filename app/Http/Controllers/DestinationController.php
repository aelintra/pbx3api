<?php

// Should be renamed endpoints
// Throttled by cluster (tenant): ?cluster=pkey returns only destinations for that tenant.
// No Trunks in response — destination lists (Inbound routes, IVRs) invoke endpoints, not trunks.

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\IpPhone;
use App\Models\Ivr;
use App\Models\Queue;
use App\Models\Tenant;

class DestinationController extends Controller
{
    /**
     * Return endpoint index for destination dropdowns (Inbound routes, IVRs).
     * Optional ?cluster={tenantPkey} — when present, only destinations for that tenant are returned.
     * Trunks are excluded (destination lists invoke endpoints: queues, extensions, IVRs, custom apps).
     * Cluster filter matches both tenant pkey and tenant id (KSUID) so it works whether DB stores pkey or id.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $clusterParam = $request->query('cluster');
        $clusterValues = $this->clusterValuesForFilter($clusterParam);

        $base = [
            'CustomApps' => $this->pkeys(Application::query()->where('active', 'YES'), $clusterValues),
            'Extensions' => $this->pkeys(IpPhone::query()->where('active', 'YES'), $clusterValues),
            'IVRs' => $this->pkeys(Ivr::query()->where('active', 'YES'), $clusterValues),
            'Queues' => $this->pkeys(Queue::query()->where('active', 'YES'), $clusterValues),
        ];

        return response()->json($base, 200);
    }

    /**
     * Resolve cluster query param (tenant pkey) to values to match in DB.
     * Returns [pkey, id, shortuid] so we match whether child tables store tenant pkey, id (KSUID), or shortuid.
     *
     * @param  string|null  $clusterParam
     * @return array|null  [pkey, id?, shortuid?] or null if no filter
     */
    private function clusterValuesForFilter($clusterParam)
    {
        if ($clusterParam === null || $clusterParam === '') {
            return null;
        }
        // Resolve tenant case-insensitively so display/capitalisation doesn't break lookup
        // (child tables store cluster using the same value as in cluster table).
        $tenant = Tenant::whereRaw('LOWER(pkey) = ?', [strtolower($clusterParam)])->first();
        if ($tenant) {
            $values = [ $tenant->pkey ];
            if (! empty($tenant->id) && $tenant->id !== $tenant->pkey) {
                $values[] = $tenant->id;
            }
            if (! empty($tenant->shortuid) && ! in_array($tenant->shortuid, $values, true)) {
                $values[] = $tenant->shortuid;
            }
            return $values;
        }
        return [ $clusterParam ];
    }

    /**
     * Apply optional cluster filter and return pkey list.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array|null  $clusterValues  [pkey, id?] or null for no filter
     * @return array
     */
    private function pkeys($query, $clusterValues)
    {
        if ($clusterValues !== null && count($clusterValues) > 0) {
            $query->whereIn('cluster', $clusterValues);
        }
        return $query->orderBy('pkey')->pluck('pkey')->toArray();
    }
}
