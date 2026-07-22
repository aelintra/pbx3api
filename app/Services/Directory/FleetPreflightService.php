<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
use App\Services\Fleet\FleetPostureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fleet readiness checks for rebuilt / onboarded nodes (S8.4).
 *
 * @phpstan-type FleetCheck array{name: string, ok: bool, detail: string}
 */
class FleetPreflightService
{
    /** @return list<FleetCheck> */
    public function run(): array
    {
        return [
            $this->checkGlobalsKsuid(),
            $this->checkOrgBucketConfigured(),
            $this->checkBackupUploadEnabled(),
            $this->checkAwsRegion(),
            $this->checkNoEmptyStaticAwsKeys(),
            $this->checkS3BackupsPrefix(),
            $this->checkNodeTenantPrefixDenied(),
            $this->checkEgressTrunk(),
            $this->checkEgressQualify(),
            $this->checkSiplogFleetOff(),
        ];
    }

    public function allPassed(): bool
    {
        foreach ($this->run() as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return FleetCheck */
    private function checkGlobalsKsuid(): array
    {
        $id = Sysglobal::query()->where('pkey', 'global')->value('id');
        if (! is_string($id) || trim($id) === '') {
            return [
                'name' => 'globals.id',
                'ok' => false,
                'detail' => 'globals.id (KSUID) is empty — patch DB or restore backup.',
            ];
        }

        return [
            'name' => 'globals.id',
            'ok' => true,
            'detail' => $id,
        ];
    }

    /** @return FleetCheck */
    private function checkOrgBucketConfigured(): array
    {
        $bucket = config('pbx3_directory.org_bucket');
        if (! is_string($bucket) || trim($bucket) === '') {
            return [
                'name' => 'PBX3_ORG_BUCKET',
                'ok' => false,
                'detail' => 'Set PBX3_ORG_BUCKET in /opt/pbx3api/.env (onboard-fleet-instance.sh).',
            ];
        }

        return [
            'name' => 'PBX3_ORG_BUCKET',
            'ok' => true,
            'detail' => $bucket,
        ];
    }

    /** @return FleetCheck */
    private function checkBackupUploadEnabled(): array
    {
        $enabled = (bool) config('pbx3_directory.backup_upload_enabled');

        return [
            'name' => 'PBX3_DIRECTORY_BACKUP_UPLOAD',
            'ok' => $enabled,
            'detail' => $enabled ? 'true' : 'false or unset — set true for fleet backups.',
        ];
    }

    /** @return FleetCheck */
    private function checkAwsRegion(): array
    {
        $region = config('filesystems.disks.pbx3_org.region') ?? env('AWS_DEFAULT_REGION');
        if (! is_string($region) || trim($region) === '') {
            return [
                'name' => 'AWS_DEFAULT_REGION',
                'ok' => false,
                'detail' => 'Set AWS_DEFAULT_REGION in .env.',
            ];
        }

        return [
            'name' => 'AWS_DEFAULT_REGION',
            'ok' => true,
            'detail' => $region,
        ];
    }

    /** @return FleetCheck */
    private function checkNoEmptyStaticAwsKeys(): array
    {
        $key = env('AWS_ACCESS_KEY_ID');
        $secret = env('AWS_SECRET_ACCESS_KEY');
        if ($key === '' || $secret === '') {
            return [
                'name' => 'AWS static keys',
                'ok' => false,
                'detail' => 'Remove empty AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY lines — use instance role.',
            ];
        }

        return [
            'name' => 'AWS static keys',
            'ok' => true,
            'detail' => ($key === null && $secret === null)
                ? 'unset (instance role OK)'
                : 'static keys set (prefer instance role on EC2)',
        ];
    }

    /** @return FleetCheck */
    private function checkS3BackupsPrefix(): array
    {
        if (! app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
            return [
                'name' => 'S3 backups prefix',
                'ok' => false,
                'detail' => 'Fleet bucket not configured — skipped list.',
            ];
        }

        $instanceId = Sysglobal::query()->where('pkey', 'global')->value('id');
        if (! is_string($instanceId) || $instanceId === '') {
            return [
                'name' => 'S3 backups prefix',
                'ok' => false,
                'detail' => 'Cannot list S3 without globals.id.',
            ];
        }

        try {
            $disk = Storage::disk('pbx3_org');
            $prefix = "instances/{$instanceId}/backups";
            $disk->directories($prefix);
        } catch (\Throwable $e) {
            return [
                'name' => 'S3 backups prefix',
                'ok' => false,
                'detail' => 'S3 access failed: '.$e->getMessage(),
            ];
        }

        return [
            'name' => 'S3 backups prefix',
            'ok' => true,
            'detail' => "list OK under {$prefix}/",
        ];
    }

    /**
     * §2.6.1 — node role must not write under tenants/* (gatekeeper / presign path).
     *
     * @return FleetCheck
     */
    private function checkNodeTenantPrefixDenied(): array
    {
        if (! app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
            return [
                'name' => 'S3 tenants/* denied',
                'ok' => false,
                'detail' => 'Fleet bucket not configured — skipped deny test.',
            ];
        }

        $probeKey = 'tenants/_fleet-preflight-iam-deny/probe.txt';

        try {
            $disk = Storage::disk('pbx3_org');
            $wrote = $disk->put($probeKey, 'preflight-deny-probe');
            if ($wrote) {
                try {
                    $disk->delete($probeKey);
                } catch (\Throwable) {
                    // best-effort cleanup
                }

                return [
                    'name' => 'S3 tenants/* denied',
                    'ok' => false,
                    'detail' => 'PutObject to tenants/* succeeded — node IAM is too broad (§2.6.1).',
                ];
            }

            return [
                'name' => 'S3 tenants/* denied',
                'ok' => true,
                'detail' => 'PutObject to tenants/* denied (§2.6.1 OK).',
            ];
        } catch (\Throwable $e) {
            if ($this->isAccessDenied($e)) {
                return [
                    'name' => 'S3 tenants/* denied',
                    'ok' => true,
                    'detail' => 'PutObject to tenants/* denied (§2.6.1 OK).',
                ];
            }

            return [
                'name' => 'S3 tenants/* denied',
                'ok' => false,
                'detail' => 'Deny probe failed: '.$e->getMessage(),
            ];
        }
    }

    private function isAccessDenied(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'accessdenied')
            || str_contains($msg, 'access denied')
            || str_contains($msg, '403')
            || str_contains($msg, 'not authorized');
    }

    /**
     * Phase A — fleet nodes must have an active Egress trunk to the SBC pool.
     *
     * @return FleetCheck
     */
    private function checkEgressTrunk(): array
    {
        $posture = app(FleetPostureService::class);
        if (! $posture->isFleetNode() && ! config('pbx3_directory.org_bucket')) {
            return [
                'name' => 'Egress trunk',
                'ok' => true,
                'detail' => 'skipped (solo node)',
            ];
        }

        $pkey = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        $row = DB::table('trunks')->where('pkey', $pkey)->first(['active', 'host']);
        if ($row === null) {
            return [
                'name' => 'Egress trunk',
                'ok' => false,
                'detail' => "Missing trunks.pkey={$pkey} — run seed-fleet-egress-trunk.sh",
            ];
        }

        if ((string) ($row->active ?? '') !== 'YES') {
            return [
                'name' => 'Egress trunk',
                'ok' => false,
                'detail' => "{$pkey} exists but is not active",
            ];
        }

        return [
            'name' => 'Egress trunk',
            'ok' => true,
            'detail' => "{$pkey} → ".((string) ($row->host ?? 'unset')),
        ];
    }

    /** @return FleetCheck */
    private function checkEgressQualify(): array
    {
        $posture = app(FleetPostureService::class);
        if (! $posture->isFleetNode() && ! config('pbx3_directory.org_bucket')) {
            return [
                'name' => 'Egress qualify',
                'ok' => true,
                'detail' => 'skipped (solo node)',
            ];
        }

        $live = $posture->egressQualifyLive();
        $state = (string) ($live['state'] ?? 'Unknown');
        if ($state === 'Avail') {
            $rtt = $live['rtt_ms'];
            $detail = $rtt !== null ? "Avail ({$rtt} ms)" : 'Avail';

            return [
                'name' => 'Egress qualify',
                'ok' => true,
                'detail' => $detail,
            ];
        }

        return [
            'name' => 'Egress qualify',
            'ok' => false,
            'detail' => $state === 'Unavail'
                ? 'Unavail — SBC OPTIONS qualify failed (see FLEET_EGRESS_AVAILABILITY_REQUIREMENTS.md)'
                : 'Unknown — AMI/qualify not available',
        ];
    }

    /**
     * Fleet nodes should not run instance dumpcap (phones via SBC).
     *
     * @return FleetCheck
     */
    private function checkSiplogFleetOff(): array
    {
        $fleetMode = (bool) config('pbx3_fleet.mode');
        if (! $fleetMode && ! config('pbx3_directory.org_bucket')) {
            return [
                'name' => 'sys-ua-siplog',
                'ok' => true,
                'detail' => 'skipped (solo node)',
            ];
        }
        if (! $fleetMode) {
            return [
                'name' => 'sys-ua-siplog',
                'ok' => true,
                'detail' => 'PBX3_FLEET_MODE unset — siplog optional (solo/debug)',
            ];
        }

        $status = @shell_exec('sv status sys-ua-siplog 2>/dev/null');
        $status = is_string($status) ? trim($status) : '';
        if ($status === '') {
            return [
                'name' => 'sys-ua-siplog',
                'ok' => true,
                'detail' => 'sv status unavailable — assume ok',
            ];
        }

        // runit: "run: …" means up; "down: …" means stopped.
        if (str_starts_with($status, 'run:')) {
            return [
                'name' => 'sys-ua-siplog',
                'ok' => false,
                'detail' => 'running on fleet node — disable: /opt/pbx3/scripts/siplog-set-mode.sh fleet',
            ];
        }

        return [
            'name' => 'sys-ua-siplog',
            'ok' => true,
            'detail' => $status !== '' ? $status : 'down',
        ];
    }
}
