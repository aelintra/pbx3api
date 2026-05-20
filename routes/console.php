<?php

use App\Models\Trunk;
use App\Services\Backup\BackupRunService;
use App\Services\Backup\LocalBackupRetention;
use App\Services\Directory\InstanceBackupDirectoryUpload;
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
        $this->info("Uploaded {$filename} to org bucket.");

        return 0;
    }
    $this->error('Upload failed — see storage/logs/laravel.log');

    return 1;
})->purpose('Upload a local /opt/pbx3/bkup zip to instances/{ksuid}/backups/ on PBX3_ORG_BUCKET');
