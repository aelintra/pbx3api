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
     * @return array{
     *   fleet: bool,
     *   egress_trunk: string,
     *   sbc_egress_host: string,
     *   sbc_egress_port: int,
     *   hide_route_paths: bool,
     *   egress_qualify: array{state: string, rtt_ms: int|null, latency: string|null}
     * }
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
            'egress_qualify' => $this->egressQualifyLive(),
        ];
    }

    /**
     * Live PJSIP qualify for the fleet Egress trunk (AMI ContactStatusDetail).
     *
     * @return array{state: string, rtt_ms: int|null, latency: string|null}
     */
    public function egressQualifyLive(): array
    {
        $unknown = ['state' => 'Unknown', 'rtt_ms' => null, 'latency' => null];
        if (! $this->isFleetNode()) {
            return $unknown;
        }
        if (! function_exists('pbx_is_running') || ! pbx_is_running()) {
            return $unknown;
        }
        if (! function_exists('get_ami_handle') || ! function_exists('pjsip_endpoint_live')) {
            return $unknown;
        }

        $pkey = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        try {
            $ami = get_ami_handle();
            $live = pjsip_endpoint_live($ami, $pkey);
            $ami->logout();
        } catch (\Throwable) {
            return $unknown;
        }

        $latency = is_string($live['latency'] ?? null) ? $live['latency'] : null;
        $rtt = null;
        if (is_string($latency) && preg_match('/(\d+)\s*ms\b/i', $latency, $m)) {
            $rtt = (int) $m[1];
        }

        return [
            'state' => (string) ($live['qualify'] ?? 'Unknown'),
            'rtt_ms' => $rtt,
            'latency' => $latency,
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
