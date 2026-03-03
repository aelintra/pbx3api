<?php

namespace App\Http\Controllers;

use App\Models\Sysglobal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class SysglobalController extends Controller

/**
 * Sysyglobals 
 * Only two methods in this class because the table only contains one row so no POST or DELETE
 * Just GET and PUT
 * 
 */
{
    //

    // globals table (sqlite_create_instance.sql). Exclude pkey, z_*, display-only (edomain, localip, sipdriver), and hidden-not-updateable (fqdninspect, fqdnprov).
    private $updateableColumns = [
        'abstimeout' => 'integer|nullable',
        'bindaddr' => 'string|nullable',
        'bindport' => 'string|nullable',
        'cosstart' => 'string|nullable',
        'emergency' => 'string|nullable',
        'fqdn' => 'string|nullable',
        'language' => 'string|nullable',
        'loglevel' => 'integer|nullable',
        'logopts' => 'string|nullable',
        'logsipdispsize' => 'integer|nullable',
        'logsipnumfiles' => 'integer|nullable',
        'logsipfilesize' => 'integer|nullable',
        'maxin' => 'integer|nullable',
        'maxout' => 'integer|nullable',
        'mycommit' => 'string|nullable',
        'natdefault' => 'string|nullable',
        'natparams' => 'string|nullable',
        'operator' => 'integer|nullable',
        'pwdlen' => 'integer|nullable',
        'recfiledlim' => 'string|nullable',
        'reclimit' => 'string|nullable',
        'recmount' => 'string|nullable',
        'recqdither' => 'string|nullable',
        'recqsearchlim' => 'string|nullable',
        'sessiontimout' => 'integer|nullable',
        'sendedomain' => 'string|nullable',
        'sipflood' => 'string|nullable',
        'sitename' => 'string|nullable',
        'staticipv4' => 'string|nullable',
        'sysop' => 'integer|nullable',
        'syspass' => 'integer|nullable',
        'tlsport' => 'integer|nullable',
        'userotp' => 'string|nullable',
        'vcl' => 'string|nullable',
        'voipmax' => 'integer|nullable',
    ];
/**
 * Return Sysglobal Index in pkey order asc
 * 
 * @return Sysglobals
 */
    public function index () {

    	$sysglobal = Sysglobal::first();
    	if (!$sysglobal) {
    		return response()->json(['error' => 'System globals not found'], 404);
    	}
    	return response()->json($sysglobal, 200);
    }


 /**
 * update sysglobal instance
 * 
 * @param  Sysglobal
 * @return sysglobal object
 */
    public function update(Request $request) {

    	$sysglobal = Sysglobal::first(); 	

// Validate         
    	$validator = Validator::make($request->all(),$this->updateableColumns);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}		

// Move post variables to the model  

		move_request_to_model($request,$sysglobal,$this->updateableColumns);  	

// store the model if it has changed
    	try {
    		if ($sysglobal->isDirty()) {
    			$sysglobal->save();
    		}

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($sysglobal, 200);

    }   


}
