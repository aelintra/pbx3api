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

class DestinationController extends Controller
{
    /**
     * Return endpoint index for destination dropdowns (Inbound routes, IVRs).
     * Optional ?cluster={tenantPkey} — when present, only destinations for that tenant are returned.
     * Trunks are excluded (destination lists invoke endpoints: queues, extensions, IVRs, custom apps).
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $cluster = $request->query('cluster');

        $base = [
            'CustomApps' => $this->pkeys(Application::query(), $cluster),
            'Extensions' => $this->pkeys(IpPhone::query(), $cluster),
            'IVRs' => $this->pkeys(Ivr::query(), $cluster),
            'Queues' => $this->pkeys(Queue::query(), $cluster),
        ];

        return response()->json($base, 200);
    }

    /**
     * Apply optional cluster filter and return pkey list.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $cluster
     * @return array
     */
    private function pkeys($query, $cluster)
    {
        if ($cluster !== null && $cluster !== '') {
            $query->where('cluster', $cluster);
        }
        return $query->orderBy('pkey')->pluck('pkey')->toArray();
    }
}
