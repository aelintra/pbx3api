<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Response;
use Validator;
use Storage;

class GreetingController extends Controller
{

	private $updateableColumns = [];

/**
 * Return Greetings Index in name order asc
 * 
 * @return Greetings
 */
    public function index () {


        $greetings = array();
    	if ($handle = opendir('/usr/share/asterisk/sounds')) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != '.' && $entry != '..') {
                    if (preg_match (' /^usergreeting.*$/ ', $entry)) {
                        array_push($greetings, $entry);
                    }
                }
            }
            closedir($handle);
            rsort($greetings);
        }
        else {
            return Response::json(['Error' => 'Could not open sounds directory '],404);
        }

        return response()->json($greetings,200);
    }

/**
 * Return (Download) named Greeting
 * 
 * @param  Backup
 * @return zip file
 */
    public function download ($greeting) {

        return Storage::disk('greetings')->download($greeting);

    }

 /**
 * Save new uploaded greeting
 * 
 * @param  Greeting
 */
    public function save (Request $request) {

        $validator = Validator::make($request->all(),[
            'greeting' => 'required|file|mimes:wav,mpeg'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $fpath = $request->greeting->storeAs('greetings', $request->greeting->getClientOriginalName());
        
        if ( ! preg_match (' /^.*(usergreeting\d{4}\.(mp3|wav))$/ ', $fpath, $matches)) {
            return Response::json(['Message' => 'File name must be of the form usergreetingxxxx.{mp3|wav} ' . $matches[1] ],422);
        }
        $filename = $matches[1];

        if (file_exists("/usr/share/asterisk/sounds/" . $filename)) {
            return Response::json(['Message' => 'UserGreeting Already exists'],422);
        }

        $fullpath = storage_path() . "/app/" . $fpath;

        shell_exec("/bin/mv $fullpath /usr/share/asterisk/sounds/");
        shell_exec("/bin/chown asterisk:asterisk /usr/share/asterisk/sounds/$filename");
        shell_exec("/bin/chmod 664 /usr/share/asterisk/sounds/$filename");
                
        return Response::json(['Uploaded ' . $filename],200);

    }
   

/**
 * Delete greeting instance
 * @param  greeting
 * @return 204 null
 */
    public function delete($greeting) {

// Don't allow deletion of default tenant

        if (!file_exists("/usr/share/asterisk/sounds/$greeting")) {
           return Response::json(['Error' => "$greeting not found in sounds set"],404); 
        }

        shell_exec("/bin/rm -r /usr/share/asterisk/sounds/$greeting");

        return response()->json(null, 204);
    }
    //
}
