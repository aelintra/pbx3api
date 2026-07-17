#!/bin/bash
set -euo pipefail

if ! grep -q 'pbx3:logs-s3-upload' /opt/pbx3api/routes/console.php; then
  cat >> /opt/pbx3api/routes/console.php <<'EOF'

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
EOF
  echo "appended artisan command"
else
  echo "artisan command already present"
fi

if ! grep -q 'pbx3:logs-s3-upload' /opt/pbx3api/bootstrap/app.php; then
  python3 - <<'PY'
from pathlib import Path
p = Path("/opt/pbx3api/bootstrap/app.php")
text = p.read_text()
needle = """        $schedule->command('pbx3:ops-register-loops')
            ->everyMinute()
            ->withoutOverlapping(2);
    })"""
insert = """        $schedule->command('pbx3:ops-register-loops')
            ->everyMinute()
            ->withoutOverlapping(2);

        // After typical daily logrotate (~06:25); needs read of /var/log (prefer root cron).
        $schedule->command('pbx3:logs-s3-upload')
            ->dailyAt('06:45')
            ->withoutOverlapping(60);
    })"""
if needle not in text:
    raise SystemExit("schedule needle not found")
p.write_text(text.replace(needle, insert, 1))
print("patched bootstrap schedule")
PY
else
  echo "schedule already present"
fi

# Tiny CDR rotate segment for smoke
printf 'accountcode,src,dst\nsmoke,100,200\n' > /var/log/asterisk/cdr-csv/Master.csv.1
chown asterisk:asterisk /var/log/asterisk/cdr-csv/Master.csv.1
chmod 640 /var/log/asterisk/cdr-csv/Master.csv.1

mkdir -p /opt/pbx3/var/log-ship
chown www-data:www-data /opt/pbx3/var/log-ship

cd /opt/pbx3api
sudo -u www-data php artisan config:clear
# root: can read syslog; instance role for S3
php artisan pbx3:logs-s3-upload --limit=3
