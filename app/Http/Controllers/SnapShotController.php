<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Response;
use Validator;
use Storage;

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
    	if ($handle = opendir('/opt/sark/snap')) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..') {
                    if (preg_match (' /^sark\.db\.\d+$/ ', $entry)) {
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
            $fsize = filesize("/opt/sark/snap/".$file);
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

        return response()->json(['newsnapshotname' => create_new_snapshot()]);

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

        $fpath = $request->uploadzip->storeAs('snaps', $request->uploadzip->getClientOriginalName());
        $fullpath = storage_path() . "/app/" . $fpath;
        shell_exec("/bin/mv $fullpath /opt/sark/snap");
        return Response::json(['Uploaded ' . $fpath],200);

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

		if (!file_exists("/opt/sark/snap/$snapshot")) {
            return Response::json(['Error' => "snapshot file not found"],404);
        } 

        shell_exec("/bin/cp /opt/sark/snap/$snapshot /opt/sark/db/sark.db");
        shell_exec("/bin/chown www-data:www-data /opt/sark/db/sark.db");
        shell_exec("/bin/chmod 664 /opt/sark/db/sark.db");

		return response()->json(['restored' => $snapshot], 200);
    }   

/**
 * Delete snapshot instance
 * @param  snapshot
 * @return [type]
 */
    public function delete($snapshot) {

// Don't allow deletion of default tenant

        if (!file_exists("/opt/sark/snap/$snapshot")) {
           return Response::json(['Error' => "$snapshot not found in snapshot set"],404); 
        }

        shell_exec("/bin/rm -r /opt/sark/snap/$snapshot");

        return response()->json(null, 204);
    }
    //
}
