<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupArchiveService;
use App\Services\Backup\BackupIndexService;
use App\Services\Backup\LocalBackupRetention;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{

	private $updateableColumns = [];

    //
/**
 * Return Backup Index in pkey order asc
 * 
 * @return Backups
 */
    public function index(BackupIndexService $index)
    {
        if (! is_dir('/opt/pbx3/bkup') && ! app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
            return Response::json(['Error' => 'Could not open bkup directory '], 509);
        }

        return response()->json($index->mergedIndex(), 200);
    }

/**
 * Return (Download) named Backup instance
 * 
 * @param  Backup
 * @return zip file
 */
    public function download ($backup) {

        return Storage::disk('backups')->download($backup);

    }

    /**
     * Presigned GET for backup.zip on S3 when local zip is missing (S5.3).
     */
    public function downloadArchiveUrl(string $backup_stamp, BackupArchiveService $archive)
    {
        if (! $archive->isAvailable()) {
            return response()->json(['Error' => 'S3 archive not configured'], 503);
        }

        try {
            return response()->json($archive->presignedDownloadUrl($backup_stamp), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['Error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('backup archive presigned URL failed', [
                'backup_stamp' => $backup_stamp,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['Error' => 'Archive not found or unavailable'], 404);
        }
    }

    /**
     * Pull backup.zip from S3 to bkup/ then run restore (S5.4).
     */
    public function restoreFromArchive(Request $request, BackupArchiveService $archive)
    {
        if (! $archive->isAvailable()) {
            return response()->json(['Error' => 'S3 archive not configured'], 503);
        }

        $validator = Validator::make($request->all(), [
            'backup_stamp' => 'required|string|regex:/^\d{8}T\d{6}Z$/',
            'restoredb' => 'boolean',
            'restoreasterisk' => 'boolean',
            'restoreusergreeting' => 'boolean',
            'restorevmail' => 'boolean',
            'restoreldap' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $stamp = (string) $request->input('backup_stamp');

        try {
            $archive->assertValidStamp($stamp);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['Error' => $e->getMessage()], 422);
        }

        $dt = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new \DateTimeZone('UTC'));
        $localFilename = 'pbx3bak.'.$dt->getTimestamp().'.zip';
        $localPath = "/opt/pbx3/bkup/{$localFilename}";

        if (! is_file($localPath)) {
            try {
                $localFilename = $archive->rehydrateToLocal($stamp);
            } catch (\Throwable $e) {
                Log::error('backup restore-from-archive rehydrate failed', [
                    'backup_stamp' => $stamp,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['Error' => 'Failed to download archive: '.$e->getMessage()], 500);
            }
        }

        $request->merge(['backup' => $localFilename]);

        $rets = restore_from_backup($request);
        if ($rets != 200) {
            return response()->json(['Error' => "{$localFilename} has errors; see logs for details"], $rets);
        }

        Log::info('backup restored from archive', [
            'backup_stamp' => $stamp,
            'local_file' => $localFilename,
        ]);

        return response()->json(['restored' => $localFilename, 'backup_stamp' => $stamp], 200);
    }

/**
 * create a new Backup instance
 * 
 * @param  Backup
 * @return new Backup zip file name
 */
    public function new () {

        try {
            $backupName = create_new_backup();

            if (app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
                dispatch(function () use ($backupName) {
                    app(InstanceBackupDirectoryUpload::class)->upload($backupName, 'manual');
                })->afterResponse();
            }

            $pruned = app(LocalBackupRetention::class)->pruneExcess();

            return response()->json([
                'newbackupname' => $backupName,
                'pruned' => $pruned,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create backup: " . $e->getMessage());
            return response()->json(['Error' => 'Failed to create backup: ' . $e->getMessage()], 500);
        }

    }

 /**
 * Save new uploaded Backup instance
 * 
 * @param  Backup
 */
    public function save (Request $request) {


        $validator = Validator::make($request->all(),[
            'uploadzip' => 'required|file|mimetypes:application/zip',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $fpath = $request->uploadzip->storeAs('bkups', $request->uploadzip->getClientOriginalName());
        $fullpath = storage_path() . "/app/" . $fpath;
        shell_exec("/bin/mv $fullpath /opt/pbx3/bkup");
        return Response::json(['Uploaded ' . $fpath],200);

    }

 /**
 * instantiate elements of a backup instance
 *
 * The backup contains the entire PBX data.  Choose the restore
 * you want by adding post entries 
 * 
 * POST values are boolean.  They can be true, false, 1, 0, "1", or "0".
 *
 *  resetdb=>true - restore the pbx db
 *  resetasterisk=>true - restore the asterisk files. N.B. be careful with this
 *  resetusergreets=>true - restore usergreetings
 *  resetvmail->true - restore voicemail
 *  resetldap->true - restore ldap contacts database 
 *  
 * 
 * @param  Backup name
 * 
 * @return 200
 */
    public function update(Request $request, $backup) {


// Validate         
    	$validator = Validator::make($request->all(),[         
            'restoredb' => 'boolean',
            'restoreasterisk' => 'boolean',
            'restoreusergreeting' => 'boolean',
            'restorevmail' => 'boolean',
            'restoreldap' => 'boolean'
        ]);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}		

		if (!file_exists("/opt/pbx3/bkup/$backup")) {
            return Response::json(['Error' => "backup file not found"],404);
        }   

        $rets = (restore_from_backup($request));

        if ($rets != 200) {
            return Response::json(['Error' => "$backup has errors see logs for details"],$rets); 
        }

		return response()->json(['restored' => $backup], 200);
    }   

/**
 * Delete backup instance
 * @param  Backup
 * @return [type]
 */
    public function delete($backup) {

// Don't allow deletion of default backup

        $backupPath = "/opt/pbx3/bkup/$backup";
        if (!file_exists($backupPath)) {
           return Response::json(['Error' => "$backup not found in backup set"],404); 
        }

        // Use syshelper for privileged delete operation
        [$response, $error] = pbx3_request_syscmd("/bin/rm -f $backupPath");
        if ($error !== null) {
            Log::error("Failed to delete backup via syshelper: $error");
            return Response::json(['Error' => "Failed to delete backup: $error"], 500);
        }

        // Verify file was deleted
        if (file_exists($backupPath)) {
            Log::error("Backup file still exists after delete: $backupPath");
            return Response::json(['Error' => "Failed to delete backup file"], 500);
        }

        return response()->json(null, 204);
    }
    //
}
