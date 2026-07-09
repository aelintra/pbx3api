<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;

/**
 * Fleet vs solo node posture (Phase A).
 */
class FleetPostureService
{
    public function isFleetNode(): bool
    {
        if ((bool) config('pbx3_fleet.mode')) {
            return true;
        }

        return $this->hasActiveEgressTrunk();
    }

    public function hasActiveEgressTrunk(): bool
    {
        $pkey = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');

        return DB::table('trunks')
            ->where('pkey', $pkey)
            ->where('active', 'YES')
            ->exists();
    }

    /**
     * @return array{fleet: bool, egress_trunk: string, sbc_egress_host: string, sbc_egress_port: int, hide_route_paths: bool}
     */
    public function toArray(): array
    {
        $fleet = $this->isFleetNode();

        return [
            'fleet' => $fleet,
            'egress_trunk' => (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress'),
            'sbc_egress_host' => (string) config('pbx3_fleet.sbc_egress_host'),
            'sbc_egress_port' => (int) config('pbx3_fleet.sbc_egress_port'),
            'hide_route_paths' => $fleet,
        ];
    }

    /**
     * Normalize outbound route trunk columns for fleet import / save.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeRouteRow(array $row): array
    {
        if (! $this->isFleetNode()) {
            return $row;
        }

        $egress = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        $row['path1'] = $egress;
        $row['path2'] = null;
        $row['path3'] = null;
        $row['path4'] = null;

        return $row;
    }
}
