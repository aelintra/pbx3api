<?php

namespace App\Services\Tenant;

use App\Models\Route;
use App\Models\Sysglobal;
use App\Models\Tenant;
use App\Services\Fleet\FleetPostureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Copy instance globals.default_outbound_dialplan onto a new tenant OutRoute (time saver).
 * Spec: pbx3/workingdocs/SEED_OUTBOUND_ON_TENANT_CREATE.md
 */
class SeedOutboundRouteOnTenantCreate
{
    public const ROUTE_PKEY = 'MainOut';

    public function __construct(
        private readonly FleetPostureService $fleetPosture,
    ) {}

    /**
     * Ensure schema column exists (lab/upgrade without waiting for deb postinst).
     */
    public function ensureSchema(): void
    {
        if (! Schema::hasTable('globals')) {
            return;
        }
        if (Schema::hasColumn('globals', 'default_outbound_dialplan')) {
            return;
        }
        try {
            DB::statement("ALTER TABLE globals ADD COLUMN default_outbound_dialplan TEXT DEFAULT '_0. _00.'");
            DB::table('globals')
                ->where(function ($q) {
                    $q->whereNull('default_outbound_dialplan')
                        ->orWhere('default_outbound_dialplan', '');
                })
                ->update(['default_outbound_dialplan' => '_0. _00.']);
        } catch (\Throwable $e) {
            Log::warning('SeedOutboundRouteOnTenantCreate: ensureSchema failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Route|null created route, or null if skipped
     */
    public function seed(Tenant $tenant): ?Route
    {
        $this->ensureSchema();

        $clusterKey = trim((string) ($tenant->shortuid ?? ''));
        if ($clusterKey === '') {
            $clusterKey = trim((string) ($tenant->pkey ?? ''));
        }
        if ($clusterKey === '') {
            return null;
        }

        $dialplan = '';
        try {
            $g = Sysglobal::query()->first();
            $dialplan = trim((string) ($g?->default_outbound_dialplan ?? ''));
        } catch (\Throwable $e) {
            Log::warning('SeedOutboundRouteOnTenantCreate: globals read failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($dialplan === '') {
            return null;
        }

        // Idempotent: do not add a second seed if tenant already has any OutRoute.
        if (Route::query()->where('cluster', $clusterKey)->exists()) {
            return null;
        }

        $path1 = null;
        $egressPkey = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        if ($this->fleetPosture->isFleetNode() || $this->trunkExists($egressPkey)) {
            $path1 = $egressPkey;
        }

        $route = new Route;
        $route->id = generate_ksuid();
        $route->shortuid = $this->newShortuid();
        $route->pkey = self::ROUTE_PKEY;
        $route->cluster = $clusterKey;
        $route->cname = 'Main outbound';
        $route->description = 'Seeded from instance default outbound dialplan';
        $route->dialplan = $dialplan;
        $route->path1 = $path1;
        $route->path2 = null;
        $route->path3 = null;
        $route->path4 = null;
        $route->active = 'YES';
        $route->auth = 'NO';
        $route->strategy = 'hunt';

        try {
            $route->save();
            set_commit_dirty();
        } catch (\Throwable $e) {
            Log::warning('SeedOutboundRouteOnTenantCreate: save failed', [
                'cluster' => $clusterKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $route;
    }

    private function trunkExists(string $pkey): bool
    {
        if ($pkey === '' || ! Schema::hasTable('trunks')) {
            return false;
        }
        try {
            return DB::table('trunks')->where('pkey', $pkey)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function newShortuid(): string
    {
        try {
            return generate_shortuid();
        } catch (\Throwable $e) {
            // Unit tests / hosts without idpwgen binary.
            $s = strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
            if (! preg_match('/[a-z]/', $s)) {
                $s = 'a'.substr($s, 1);
            }

            return $s;
        }
    }
}
