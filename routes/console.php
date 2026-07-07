<?php

use App\Models\Trunk;
use App\Services\Backup\BackupRunService;
use App\Services\Backup\LocalBackupRetention;
use App\Services\Directory\FleetPreflightService;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use App\Services\Tenant\TenantMobilityService;
use App\Services\TenantDefaultBackfillService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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

Artisan::command('pbx3:recordings-reconcile {--tenant= : Limit to one tenant shortuid}', function (
    \App\Services\Recordings\RecordingReconcileService $reconcile,
) {
    $tenant = $this->option('tenant');
    $stats = $reconcile->run(is_string($tenant) && $tenant !== '' ? $tenant : null);
    $this->info(sprintf('Reconcile: %d inserted, %d already indexed', $stats['inserted'], $stats['skipped']));

    return 0;
})->purpose('Backfill recordings table from local archive files (R1.5)');
