<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SnapShotController extends Controller
{

	private $updateableColumns = [];

    //
/**
 * Return SnapShot Index 
 * 
 * @return Snaps
 */
    public function index () {

        $snap = array();
    	if ($handle = opendir('/opt/pbx3/snap')) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..') {
                    if (preg_match (' /^(pbx3|sqlite)\.db\.\d+$/ ', $entry)) {
                        array_push($snap, $entry);
                    }
                }
            }
            closedir($handle);
            rsort($snap);
        }
        else {
            return Response::json(['Error' => 'Could not open snap directory '],509);
        }

        $snaps = array ();
        foreach ($snap as $file ) {
            preg_match( '/\.(\d+)$/',$file,$matches);       
            $rdate = date('D d M H:i:s Y', $matches[1]);
            $fsize = filesize("/opt/pbx3/snap/".$file);
            $snaps[$file]["filesize"] = $fsize;
            $snaps[$file]["date"] = $rdate;                
        }

        return response()->json($snaps,200);
    }

/**
 * Return (Download) named Snap instance
 * 
 * @param  Snapshot
 * @return SQlite3 db  file
 */
    public function download ($snapshot) {

        return Storage::disk('snapshots')->download($snapshot);

    }

/**
 * create a new SnapShot instance
 * 
 * @param  Snapshot
 * @return new Snapshot file name
 */
    public function new () {

        try {
            $snapshotName = create_new_snapshot();
            return response()->json(['newsnapshotname' => $snapshotName]);
        } catch (\Exception $e) {
            Log::error("Failed to create snapshot: " . $e->getMessage());
            return response()->json(['Error' => 'Failed to create snapshot: ' . $e->getMessage()], 500);
        }

    }

 /**
 * Save new uploaded snapshot instance
 * 
 * @param  Snapshot
 */
    public function save (Request $request) {


        $validator = Validator::make($request->all(),[
            'uploadsnap' => 'required|file|mimetypes:application/octet-stream',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $fpath = $request->uploadsnap->storeAs('snaps', $request->uploadsnap->getClientOriginalName());
        $fullpath = storage_path() . "/app/" . $fpath;
        $finalPath = "/opt/pbx3/snap/" . $request->uploadsnap->getClientOriginalName();
        
        // Verify uploaded file exists before moving
        if (!file_exists($fullpath)) {
            Log::error("Uploaded snapshot file not found at $fullpath");
            return Response::json(['Error' => "Failed to upload snapshot: file not found"], 500);
        }
        
        // Use syshelper for privileged move operation
        [$response, $error] = pbx3_request_syscmd("/bin/mv $fullpath $finalPath");
        if ($error !== null) {
            Log::error("Failed to move snapshot via syshelper: $error");
            return Response::json(['Error' => "Failed to upload snapshot: $error"], 500);
        }
        
        // Verify file was moved successfully
        if (!file_exists($finalPath)) {
            Log::error("Snapshot file was not moved to $finalPath");
            return Response::json(['Error' => "Failed to upload snapshot: file not moved"], 500);
        }
        
        return Response::json(['Uploaded ' . $request->uploadsnap->getClientOriginalName()],200);

    }

 /**
 * instantiate a snapshot instance
 *
 * The snapshot contains the entire PBX DB.  
 *  
 * 
 * @param  snapshot name
 * 
 * @return 200
 */
    public function update(Request $request, $snapshot) {


// Validate         	

		if (!file_exists("/opt/pbx3/snap/$snapshot")) {
            return Response::json(['Error' => "snapshot file not found"],404);
        } 

        // Use syshelper for privileged restore operations
        [$response, $error] = pbx3_request_syscmd("/bin/cp /opt/pbx3/snap/$snapshot /opt/pbx3/db/sqlite.db");
        if ($error !== null) {
            Log::error("Failed to restore snapshot via syshelper: $error");
            return Response::json(['Error' => "Failed to restore snapshot: $error"], 500);
        }
        [$response, $error] = pbx3_request_syscmd("/bin/chown www-data:www-data /opt/pbx3/db/sqlite.db");
        if ($error !== null) {
            Log::warning("Failed to chown restored snapshot: $error");
        }
        [$response, $error] = pbx3_request_syscmd("/bin/chmod 664 /opt/pbx3/db/sqlite.db");
        if ($error !== null) {
            Log::warning("Failed to chmod restored snapshot: $error");
        }

		return response()->json(['restored' => $snapshot], 200);
    }   

/**
 * Delete snapshot instance
 * @param  snapshot
 * @return [type]
 */
    public function delete($snapshot) {

// Don't allow deletion of default tenant

        $snapshotPath = "/opt/pbx3/snap/$snapshot";
        if (!file_exists($snapshotPath)) {
           return Response::json(['Error' => "$snapshot not found in snapshot set"],404); 
        }

        // Use syshelper for privileged delete operation
        [$response, $error] = pbx3_request_syscmd("/bin/rm -f $snapshotPath");
        if ($error !== null) {
            Log::error("Failed to delete snapshot via syshelper: $error");
            return Response::json(['Error' => "Failed to delete snapshot: $error"], 500);
        }

        // Verify file was deleted
        if (file_exists($snapshotPath)) {
            Log::error("Snapshot file still exists after delete: $snapshotPath");
            return Response::json(['Error' => "Failed to delete snapshot file"], 500);
        }

        return response()->json(null, 204);
    }
    //
}
