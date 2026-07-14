<?php

namespace App\Services\Recordings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Async upload of local archive recordings to PBX3_RECORDINGS_BUCKET via gatekeeper
 * presigned PUT (S7). Fail-safe: telephony continues if gatekeeper/S3 is down.
 */
class RecordingS3UploadService
{
    private const TAGGING = 'class=recording';

    public function __construct(
        private readonly GatekeeperRecordingsClient $gatekeeper,
        private readonly RecordingPathHelper $paths,
        private readonly RecordingSchemaService $schema,
    ) {}

    public function isConfigured(): bool
    {
        if (! (bool) config('pbx3_recordings.upload_enabled')) {
            return false;
        }

        return $this->gatekeeper->isConfigured();
    }

    /**
     * @return array{uploaded: int, skipped: int, errors: int}
     */
    public function run(?int $limit = null): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0];

        if (! $this->isConfigured()) {
            return $stats;
        }

        if (! $this->schema->ensureTable()) {
            $stats['errors']++;

            return $stats;
        }

        $limit = $limit ?? max(1, (int) config('pbx3_recordings.upload_batch', 50));
        $allow = $this->tenantAllowlist();

        $query = DB::table('recordings')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('s3_key')->orWhere('s3_key', '');
            })
            ->whereIn('location', [
                RecordingPathHelper::LOCATION_ARCHIVE,
                RecordingPathHelper::LOCATION_SPOOL,
            ])
            ->orderBy('epoch')
            ->limit($limit);

        if ($allow !== null) {
            $query->whereIn('cluster', $allow);
        }

        foreach ($query->get() as $row) {
            if ($this->uploadRow($row)) {
                $stats['uploaded']++;
            } else {
                // distinguish skip vs error roughly via local file missing
                $local = (string) ($row->local_path ?? '');
                if ($local === '' || ! is_file($local)) {
                    $stats['skipped']++;
                } else {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    private function uploadRow(object $row): bool
    {
        $tenant = (string) $row->cluster;
        $filename = (string) $row->filename;
        $local = (string) ($row->local_path ?? '');
        $epoch = (int) ($row->epoch ?? 0);

        if ($local === '' || ! is_file($local)) {
            Log::debug('recording s3 upload skipped: local file missing', [
                'id' => $row->id,
                'path' => $local,
            ]);

            return false;
        }

        $key = $this->paths->s3ObjectKey($tenant, $epoch, $filename);

        try {
            $presign = $this->gatekeeper->presign('PUT', $key, self::TAGGING);
            $verify = (bool) config('pbx3_recordings.gatekeeper_http_verify', true);

            $response = Http::withOptions([
                'verify' => $verify,
                'body' => (string) file_get_contents($local),
            ])
                ->withHeaders([
                    'x-amz-tagging' => self::TAGGING,
                ])
                ->timeout(120)
                ->send('PUT', $presign['url']);

            if (! $response->successful()) {
                Log::warning('recording s3 PUT failed', [
                    'id' => $row->id,
                    'key' => $key,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('recording s3 upload exception', [
                'id' => $row->id,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        DB::table('recordings')->where('id', $row->id)->update([
            's3_key' => $key,
            'z_updated' => $now,
            'z_updater' => 'recordings-s3-upload',
        ]);

        return true;
    }

    /**
     * @return list<string>|null  null = all tenants
     */
    private function tenantAllowlist(): ?array
    {
        $raw = config('pbx3_recordings.upload_tenants');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $list === [] ? null : $list;
    }
}
