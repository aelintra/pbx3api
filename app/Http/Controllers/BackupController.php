<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Response;
use Validator;
use Storage;

class BackupController extends Controller
{

	private $updateableColumns = [];

    //
/**
 * Return Backup Index in pkey order asc
 * 
 * @return Backups
 */
    public function index () {

        $bkup = array();
    	if ($handle = opendir('/opt/sark/bkup')) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..') {
                    if (preg_match (' /^sarkbak\.\d+\.zip$/ ', $entry)) {
                        array_push($bkup, $entry);
                    }
                }
            }
            closedir($handle);
            rsort($bkup);
        }
        else {
            return Response::json(['Error' => 'Could not open bkup directory '],509);
        }

        $backups = array ();
        foreach ($bkup as $file ) {
            preg_match( '/\.(\d+).zip$/',$file,$matches);       
            $rdate = date('D d M H:i:s Y', $matches[1]);
            $fsize = filesize("/opt/sark/bkup/".$file);
            $backups[$file]["filesize"] = $fsize;
            $backups[$file]["date"] = $rdate;                
        }

        return response()->json($backups,200);
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
 * create a new Backup instance
 * 
 * @param  Backup
 * @return new Backup zip file name
 */
    public function new () {

        return response()->json(['newbackupname' => create_new_backup()]);

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
        shell_exec("/bin/mv $fullpath /opt/sark/bkup");
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

		if (!file_exists("/opt/sark/bkup/$backup")) {
            return Response::json(['Error' => "backup file not found"],404);
        }   

        $rets = (restore_from_backup($request));

        if ($rets != 200) {
            return Response::json(['Error' => "$backup has errors see logs for details"],$rets); 
        }

		return response()->json(['restored' => $backup], 200);
    }   

/**
 * Delete tenant instance
 * @param  Backup
 * @return [type]
 */
    public function delete($backup) {

// Don't allow deletion of default tenant

        if (!file_exists("/opt/sark/bkup/$backup")) {
           return Response::json(['Error' => "$backup not found in backup set"],404); 
        }

        shell_exec("/bin/rm -r /opt/sark/bkup/$backup");

        return response()->json(null, 204);
    }
    //
}
