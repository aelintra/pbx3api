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

    private $updateableColumns = [

    	'ABSTIMEOUT' => 'integer',
    	'ACL' => 'in:NO,YES',
    	'AGENTSTART' => 'integer',
    	'ALERT' => 'email',
    	'ALLOWHASHXFER' => 'in:enabled,disabled',
    	'BLINDBUSY' => 'integer|nullable',
    	'BOUNCEALERT' => 'integer|nullable',
    	'CALLPARKING' => 'in:NO,YES',
    	'CALLRECORD1' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
    	'CAMPONQONOFF' => 'in:OFF,ON',
    	'CAMPONQOPT' => 'string|nullable',
	    'CFWDEXTRNRULE' => 'in:enabled,disabled',
    	'CFWDPROGRESS' => 'in:enabled,disabled',
    	'CFWDANSWER' => 'in:enabled,disabled',
    	'CLUSTER' => 'in:ON,OFF',
    	'CONFTYPE' => 'in:simple,hosted',
    	'COSSTART' => 'in:ON,OFF',
    	'COUNTRYCODE' => 'alpha|size:2',
    	'EURL' => 'regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
    	'EMERGENCY' => 'digits:3',
    	'FQDN' => 'regex:/^(?!:\/\/)(?=.{1,255}$)((.{1,63}\.){1,127}(?![0-9]*$)[a-z0-9-]+\.?)$/i',
    	'FQDNINSPECT' => 'in:NO,YES',
    	'FQDNPROV' => 'in:NO,YES',
    	'INTRINGDELAY' => 'integer',
    	'IVRKEYWAIT' => 'integer|nullable',
    	'IVRDIGITWAIT' => 'integer|nullable',
    	'LACL' => 'in:NO,YES',
    	'LDAPBASE' => 'string|nullable',
    	'LDAPOU' => 'string|nullable',
    	'LDAPUSER' => 'string|nullable',
    	'LDAPPASS' => 'string|nullable',
    	'LEASEHDTIME' => 'integer',
    	'LOCALIP' => 'ip',
    	'LOGLEVEL' => 'integer',    	
    	'LOGSIPDISPSIZE' => 'integer',
    	'LOGSIPNUMFILES' => 'integer',
    	'LOGSIPFILESIZE' => 'integer',
    	'LTERM' => 'in:NO,YES',
    	'MAXIN' => 'integer',
    	'MIXMONITOR' => 'in:NO,YES',
    	'MONITOROUT' => 'string',
    	'MONITORSTAGE' => 'string',
    	'MONITORTYPE' => 'in:monitor,mixmonitor',
    	'NATDEFAULT' => 'in:local,remote',
    	'OPERATOR' => 'integer',
    	'PWDLEN' => 'integer',    
    	'PLAYBEEP' => 'in:YES.NO',
    	'PLAYBUSY' => 'in:YES,NO',
    	'PLAYCONGESTED' => 'in:YES,NO',
    	'PLAYTRANSFER' => 'in:YES,NO',
    	'RECFINALDEST' => 'string',
    	'RECLIMIT' => 'integer',
    	'RECQDITHER' => 'integer',
    	'RECQSEARCHLIM' => 'integer',    	
    	'RINGDELAY' => 'integer',
    	'SESSIONTIMOUT' => 'integer',
    	'SENDEDOMAIN' => 'in:YES,NO',
    	'SIPIAXSTART' => 'integer',
    	'SIPFLOOD' => 'in:NO,YES',
    	'SPYPASS' => 'integer',
    	'SUPEMAIL' => 'email|nullable',
    	'SYSOP' => 'integer',
    	'SYSPASS' => 'integer',    
    	'TLSPORT' => 'integer',
    	'USEROTP' => 'string',
    	'USERCREATE' => 'in:NO,YES',
   		'VDELAY' => 'integer',
    	'VMAILAGE' => 'integer',
    	'VOICEINSTR' => 'in:YES,NO',
    	'VOIPMAX' => 'integer',
    	'VXT' => 'boolean',
    	'ZTP' => 'in:disabled,enabled'
    ];
/**
 * Return Sysglobal Index in pkey order asc
 * 
 * @return Sysglobals
 */
    public function index () {

    	return Sysglobal::first();
    }


 /**
 * update sysglobal instance
 * 
 * @param  Sysglobal
 * @return sysglobal object
 */
    public function update(Request $request) {

    	$sysglobal = SysGlobal::first(); 	

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

		return response()->json($sysglobal->first(), 200);

    }   


}
