<?php

use App\Models\Trunk;
use App\Services\Backup\BackupRunService;
use App\Services\Backup\LocalBackupRetention;
use App\Services\Directory\FleetPreflightService;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use App\Services\Snapshot\SnapshotRetention;
use App\Services\Tenant\TenantMobilityService;
use App\Services\TenantDefaultBackfillService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pbx3:ops-register-loops', function (\App\Services\Ops\RegisterLoopScanner $scanner) {
    $result = $scanner->run();
    $this->info(sprintf(
        'scanned=%d matched=%d emitted=%d skipped_not_wl=%d errors=%d',
        $result['scanned'],
        $result['matched'],
        $result['emitted'],
        $result['skipped_not_whitelisted'],
        count($result['errors'])
    ));
    foreach ($result['errors'] as $err) {
        $this->warn($err);
    }

    return count($result['errors']) > 0 && $result['emitted'] === 0 ? 1 : 0;
})->purpose('Scan Asterisk auth failures on Fail2ban whitelist; notify Gatekeeper');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('tenant:backfill-defaults', function (TenantDefaultBackfillService $backfill) {
    $this->info('Backfilling NULL columns to new schema defaults...');
    $summary = $backfill->run();
    $total = 0;
    foreach ($summary as $table => $columns) {
        foreach ($columns as $column => $count) {
            if ($count > 0) {
                $this->line("  {$table}.{$column}: {$count} row(s) updated");
                $total += $count;
            }
        }
    }
    $this->info($total > 0 ? "Done. {$total} row(s) updated." : 'Done. No NULLs to backfill (or tables missing).');
})->purpose('Set NULL columns to new defaults in existing tenant rows (one-time after schema migration)');

Artisan::command('trunks:set-default-cluster', function () {
    $updated = Trunk::query()->update(['cluster' => 'default']);
    $this->info("Done. {$updated} trunk(s) set to cluster = 'default'.");
})->purpose('Set all existing trunks to the default cluster (TRUNK_ROUTE_MULTITENANCY first cut)');

Artisan::command('pbx3:backup-run {--trigger=scheduled : manual|scheduled|pre-upgrade}', function (BackupRunService $runner) {
    $trigger = $this->option('trigger');
    if (! in_array($trigger, ['manual', 'scheduled', 'pre-upgrade'], true)) {
        $this->error('Invalid --trigger');

        return 1;
    }

    try {
        $result = $runner->run($trigger);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info('Created '.$result['backup_name']);
    if ($result['s3_uploaded']) {
        $this->info('Uploaded to org bucket.');
    } elseif (app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
        $this->warn('S3 upload failed (local backup kept) — see storage/logs/laravel.log');
    }
    if ($result['pruned'] !== []) {
        $this->line('Pruned local: '.implode(', ', $result['pruned']));
    }

    return 0;
})->purpose('Create local backup, upload to org bucket if configured, FIFO prune to local_max_count');

Artisan::command('pbx3:prune-backups', function (LocalBackupRetention $retention) {
    $removed = $retention->pruneExcess();
    if ($removed === []) {
        $this->info('Nothing to prune.');

        return 0;
    }
    $this->info('Removed: '.implode(', ', $removed));

    return 0;
})->purpose('FIFO prune /opt/pbx3/bkup to PBX3_BACKUP_LOCAL_MAX_COUNT (does not touch S3)');

Artisan::command('pbx3:prune-snapshots', function (SnapshotRetention $retention) {
    $removed = $retention->pruneExcess();
    if ($removed === []) {
        $this->info('Nothing to prune.');

        return 0;
    }
    $this->info('Removed: '.implode(', ', $removed));

    return 0;
})->purpose('FIFO prune /opt/pbx3/snap to PBX3_SNAPSHOT_MAX_COUNT');

Artisan::command('pbx3:upload-backup {filename : e.g. pbx3bak.1716123456.zip} {--trigger=manual : manual|scheduled|pre-upgrade}', function (InstanceBackupDirectoryUpload $upload) {
    $filename = basename($this->argument('filename'));
    $trigger = $this->option('trigger');
    if (! in_array($trigger, ['manual', 'scheduled', 'pre-upgrade'], true)) {
        $this->error('Invalid --trigger');

        return 1;
    }
    if (! $upload->isConfigured()) {
        $this->error('Set PBX3_ORG_BUCKET (and AWS credentials or instance role).');

        return 1;
    }
    $ok = $upload->upload($filename, $trigger);
    if ($ok) {
        $bucket = config('pbx3_directory.org_bucket');
        $this->info("Uploaded {$filename} to s3://{$bucket}/instances/…/backups/… (see laravel.log for key)");

        return 0;
    }
    $this->error('Upload failed — see storage/logs/laravel.log');

    return 1;
})->purpose('Upload a local /opt/pbx3/bkup zip to instances/{ksuid}/backups/ on PBX3_ORG_BUCKET');

Artisan::command('tenant:export {tenant : cluster id, shortuid, or pkey} {--include-recordings : Bundle on-node recording files} {--output= : Override output zip path}', function (TenantMobilityService $mobility) {
    try {
        $result = $mobility->export($this->argument('tenant'), [
            'include_recordings' => (bool) $this->option('include-recordings'),
            'output_path' => $this->option('output') ?: null,
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $manifest = $result['manifest'];
    $tenant = $manifest['tenant'] ?? [];
    $this->info('Exported tenant '.$tenant['pkey'].' ('.$tenant['shortuid'].')');
    $this->line('Zip: '.$result['zip_path']);
    foreach ($manifest['row_counts'] ?? [] as $table => $count) {
        if ($count > 0) {
            $this->line("  {$table}: {$count}");
        }
    }

    return 0;
})->purpose('Export one tenant to pbx3tenant.{shortuid}.{epoch}.zip (S8.6)');

Artisan::command('tenant:import {zip : Path to export zip} {--replace : Overwrite existing tenant with same id/shortuid/pkey} {--skip-media : Import DB only}', function (TenantMobilityService $mobility) {
    try {
        $result = $mobility->import($this->argument('zip'), [
            'replace' => (bool) $this->option('replace'),
            'skip_media' => (bool) $this->option('skip-media'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $tenant = $result['tenant'] ?? [];
    $this->info('Imported tenant '.$tenant['pkey'].' ('.$tenant['shortuid'].')');
    foreach ($result['imported_rows'] ?? [] as $table => $count) {
        if ($count > 0) {
            $this->line("  {$table}: +{$count}");
        }
    }
    $media = $result['media'] ?? [];
    if (! empty($media['greetings'])) {
        $this->line('Greeting media installed.');
    }
    if (! empty($media['recordings'])) {
        $this->line('Recording media installed.');
    }
    $this->line('Shorewall FQDN inline rules refreshed (when globals.fqdninspect is YES).');
    $this->comment('Next: SPA Commit, Certificates Sync, DNS cutover, move-tenant.sh — see TENANT_MIGRATION_RUNBOOK.md');

    return 0;
})->purpose('Import tenant from export zip; preserves cluster.id KSUID (S8.6)');

Artisan::command('pbx3:fleet-preflight', function (FleetPreflightService $preflight) {
    $checks = $preflight->run();
    $failed = 0;
    foreach ($checks as $check) {
        $mark = $check['ok'] ? 'OK' : 'FAIL';
        $this->line(sprintf('[%s] %s — %s', $mark, $check['name'], $check['detail']));
        if (! $check['ok']) {
            $failed++;
        }
    }
    if ($failed > 0) {
        $this->error("Fleet preflight: {$failed} check(s) failed.");

        return 1;
    }
    $this->info('Fleet preflight: all checks passed.');

    return 0;
})->purpose('Verify fleet readiness: KSUID, PBX3_ORG_BUCKET, instance-role S3 access (S8.4)');

Artisan::command('pbx3:recordings-offload', function (\App\Services\Recordings\RecordingOffloadService $offload) {
    $stats = $offload->run();
    $this->info(sprintf(
        'Offload complete: %d moved, %d skipped (not stable), %d errors',
        $stats['offloaded'],
        $stats['skipped'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Move stable spool recordings to local archive and index rows (R1.5)');

Artisan::command('pbx3:recordings-s3-upload {--limit= : Max rows this run}', function (
    \App\Services\Recordings\RecordingS3UploadService $upload,
) {
    if (! $upload->isConfigured()) {
        $this->warn('S3 upload not configured (set PBX3_RECORDING_UPLOAD_ENABLED + PBX3_GATEKEEPER_URL + PBX3_GATEKEEPER_TOKEN).');

        return 0;
    }

    $limitOpt = $this->option('limit');
    $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
    $stats = $upload->run($limit);
    $this->info(sprintf(
        'S3 upload: %d uploaded, %d skipped, %d errors',
        $stats['uploaded'],
        $stats['skipped'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Upload local archive recordings to dedicated S3 bucket via gatekeeper presign (S7)');

Artisan::command('pbx3:recordings-retain {--usage-only : Only update cluster.recused}', function (
    \App\Services\Recordings\RecordingRetentionService $retention,
    \App\Services\Recordings\RecordingUsageService $usage,
) {
    if ((bool) $this->option('usage-only')) {
        $count = $usage->updateAllTenants();
        $this->info("Updated recused for {$count} tenant(s).");

        return 0;
    }

    $stats = $retention->run();
    $this->info(sprintf(
        'Retention: %d aged, %d purged, %d size-evicted, %d errors',
        $stats['aged'],
        $stats['purged'],
        $stats['size_evicted'],
        $stats['errors']
    ));

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Recording retention (age-out, grace purge, recmaxsize) and recused tally (R1.5)');

Artisan::command('pbx3:recordings-migrate-schema', function (\App\Services\Recordings\RecordingSchemaService $schema) {
    if ($schema->ensureTable()) {
        $this->info('recordings table is present.');

        return 0;
    }
    $this->error('Failed to create recordings table — check PBX3_RECORDINGS_SCHEMA_SQL and laravel.log');

    return 1;
})->purpose('Apply sqlite_add_recordings_table.sql to the instance DB (R1.5)');

Artisan::command('pbx3:recordings-reconcile {--tenant= : Limit to one tenant shortuid} {--no-drift : Only backfill from archive (skip S3/local drift)}', function (
    \App\Services\Recordings\RecordingReconcileService $reconcile,
) {
    $tenant = $this->option('tenant');
    $drift = ! (bool) $this->option('no-drift');
    $stats = $reconcile->run(
        is_string($tenant) && $tenant !== '' ? $tenant : null,
        $drift,
    );
    $this->info(sprintf(
        'Reconcile: %d inserted, %d already indexed, %d repaired, %d removed, %d errors',
        $stats['inserted'],
        $stats['skipped'],
        $stats['repaired'],
        $stats['removed'],
        $stats['errors']
    ));

    return ($stats['errors'] ?? 0) > 0 ? 1 : 0;
})->purpose('Backfill recordings index from archive and repair SQLite ↔ disk ↔ S3 drift (S7.10)');

Artisan::command('pbx3:logs-s3-upload {--limit= : Max files this run}', function (
    \App\Services\Directory\InstanceLogDirectoryUpload $upload,
) {
    if (! $upload->isConfigured()) {
        $this->warn('Log S3 upload not configured (set PBX3_ORG_BUCKET; PBX3_LOG_UPLOAD_ENABLED defaults true).');

        return 0;
    }

    $limitOpt = $this->option('limit');
    $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
    $stats = $upload->run($limit);
    $this->info(sprintf(
        'Log ship: %d uploaded, %d skipped, %d errors',
        $stats['uploaded'],
        $stats['skipped'],
        $stats['errors']
    ));
    foreach ($stats['details'] as $line) {
        $this->line('  '.$line);
    }

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Upload rotated syslog / Asterisk messages / CDR CSV to org bucket logs/ (Phase 1)');

Artisan::command('pbx3:cdr-prune {--days= : Override local_days.cdr}', function (
    \App\Services\Cdr\CdrRetentionService $retention,
) {
    $daysOpt = $this->option('days');
    $days = is_numeric($daysOpt) ? (int) $daysOpt : null;
    try {
        $stats = $retention->prune($days);
    } catch (\Throwable $e) {
        $this->error('CDR prune failed: '.$e->getMessage());

        return 1;
    }

    if (! $stats['available']) {
        $this->warn('CDR SQLite not available at '.$stats['path']);

        return 0;
    }

    $this->info(sprintf(
        'CDR prune: deleted %d rows older than %s (local_days=%d)',
        $stats['deleted'],
        $stats['cutoff'],
        $stats['local_days']
    ));

    return 0;
})->purpose('Prune Asterisk master.db CDR rows older than local retention (Phase 6)');
