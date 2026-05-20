<?php

namespace App\Services\Backup;

use App\Services\Directory\InstanceBackupDirectoryUpload;

/**
 * Create local backup, optional S3 upload, FIFO prune (option C local leg).
 */
class BackupRunService
{
    public function __construct(
        private readonly LocalBackupRetention $retention,
        private readonly InstanceBackupDirectoryUpload $directoryUpload,
    ) {}

    /**
     * @param  'manual'|'scheduled'|'pre-upgrade'  $trigger
     * @return array{backup_name: string, pruned: list<string>, s3_uploaded: bool}
     */
    public function run(string $trigger = 'manual'): array
    {
        $backupName = create_new_backup();

        $s3Uploaded = false;
        if ($this->directoryUpload->isConfigured()) {
            $s3Uploaded = $this->directoryUpload->upload($backupName, $trigger);
        }

        $pruned = $this->retention->pruneExcess();

        return [
            'backup_name' => $backupName,
            'pruned' => $pruned,
            's3_uploaded' => $s3Uploaded,
        ];
    }
}
