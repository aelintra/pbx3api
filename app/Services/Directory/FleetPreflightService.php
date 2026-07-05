<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
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
}
