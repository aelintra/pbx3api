<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Response;
use Validator;
use Storage;

class LogController extends Controller
{

	private $updateableColumns = [];

/**
 * Return Greetings Index in name order asc
 * 
 * @return Greetings
 */
    public function index () {

        return response()->json(['Log' => 'Master.csv'],200);
    }

/**
 * Return (Download) CDR
 * 
 * @param  REQUEST
 * @return csv file
 */
    public function showcdr (Request $request) {

    // Validate         
        $validator = Validator::make($request->all(),[         
            'limit' => 'numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $dname = "/tmp/Master." . time() . ".csv";
        $cmd = "/bin/cat";
        if (isset($request->limit)) {
            $cmd = "/usr/bin/tail -n $limit";
        }
       
        shell_exec(" $cmd /var/log/asterisk/cdr-csv/Master.csv > $dname");

        return Response::download($dname)->deleteFileAfterSend(true);

    }
   

    //
}
