<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{

	// cluster table (full_schema.sql). Exclude id, pkey, shortuid, z_*. Display id/pkey in UI.
	private $updateableColumns = [
			'abstimeout' => 'integer',
			'active' => 'in:YES,NO',
			'allow_hash_xfer' => 'in:enabled,disabled',
			'blind_busy' => 'string|nullable',
			'bounce_alert' => 'string|nullable',
			'callrecord_1' => 'in:None,In,Out,Both',
			'camp_on_q_onoff' => 'string|nullable',
			'camp_on_q_opt' => 'string|nullable',
			'cfwdextern_rule' => 'in:YES,NO',
			'cfwd_progress' => 'in:enabled,disabled',
			'cfwd_answer' => 'in:enabled,disabled',
			'clusterclid' => 'nullable|string|regex:/^\d*$/',
			'chanmax' => 'integer',
			'cname' => 'string|nullable',
			'countrycode' => 'integer',
			'description' => 'string',
			'devicerec' => 'string|nullable',
			'dynamicfeatures' => 'string|nullable',
			'emailalert' => 'string|nullable',
			'emergency' => 'string|nullable',
			'ext_lim' => 'integer|nullable',
			'ext_len' => 'integer|nullable',
			'fqdn' => 'string|nullable',
			'fqdninspect' => 'boolean',
			'int_ring_delay' => 'integer',
			'ivr_key_wait' => 'integer',
			'ivr_digit_wait' => 'integer',
			'language' => 'string|nullable',
			'ldapanonbind' => 'nullable|in:YES,NO',
			'ldapbase' => 'string|nullable',
			'ldaphost' => 'string|nullable',
			'ldapou' => 'string|nullable',
			'ldapuser' => 'string|nullable',
			'ldappass' => 'nullable|string',
			'ldaptls' => 'in:on,off',
			'localarea' => 'numeric|nullable',
			'localdplan' => ['regex:/^_X+$/', 'nullable'],
			'lterm' => 'integer|nullable',
			'leasedhdtime' => 'integer|nullable',
			'masteroclo' => 'in:AUTO,CLOSED',
			'maxin' => 'integer',
			'maxout' => 'integer|nullable',
			'mixmonitor' => 'string|nullable',
			'monitor_out' => 'string|nullable',
			'monitor_stage' => 'string|nullable',
			'operator' => 'integer',
			'play_beep' => 'integer|nullable',
			'play_busy' => 'integer|nullable',
			'play_congested' => 'integer|nullable',
			'play_transfer' => 'integer|nullable',
			'rec_age' => 'integer',
			'rec_final_dest' => 'string|nullable',
			'rec_file_dlim' => 'string|nullable',
			'rec_grace' => 'integer',
			'rec_limit' => 'integer|nullable',
			'rec_mount' => 'string|nullable',
			'recmaxage' => 'integer|nullable',
			'recmaxsize' => 'integer|nullable',
			'ringdelay' => 'integer',
			'spy_pass' => 'nullable|string|max:64',
			'sysop' => 'integer|nullable',
			'syspass' => 'nullable|string|max:64',
			'usemohcustom' => 'string|nullable',
			'VDELAY' => 'integer|nullable',
			'vmail_age' => 'integer',
			'voice_instr' => 'integer|nullable',
			'voip_max' => 'integer',
	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

    //
/**
 * Return Tenant Index in pkey order asc
 * 
 * @return Tenants
 */
    public function index () {

    	return Tenant::orderBy('pkey','asc')->get();
    }

    /** Export tenants list as PDF. Same dataset as index. */
    public function exportPdf()
    {
        $tenants = Tenant::orderBy('pkey', 'asc')->get();
        return Pdf::loadView('exports.tenants-pdf', ['tenants' => $tenants])
            ->setPaper('a4', 'landscape')
            ->download('tenants.pdf');
    }

/**
 * Return named Tenant instance
 * 
 * @param  Tenant
 * @return Tenant object
 */
    public function show (Tenant $tenant) {
    	return $tenant;
    }

 /**
 * Save new tenant instance
 * 
 * @param  Tenant
 */
    public function save (Request $request) {

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required|string',
            'description' => 'string|required',
        ]);

    	$validator = Validator::make($request->all(), $createRules); 

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        if (Tenant::where('pkey','=',$request->pkey)->count()) {
           return Response::json(['Error' => 'Key already exists'],409); 
        }

    	$tenant = new Tenant;
		$tenant->id = generate_ksuid();
		$tenant->shortuid = generate_shortuid();

// Move post variables to the model 
    	move_request_to_model($request, $tenant, $createRules); 

// store the new model
    	try {

    		$tenant->save();

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

    	return $tenant;
    }

 /**
 * update tenant instance
 * 
 * @param  Tenant
 * @return tenant object
 */
    public function update(Request $request, Tenant $tenant) {


// Validate         
    	$validator = Validator::make($request->all(),$this->updateableColumns);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}		

// Move post variables to the model  

		move_request_to_model($request,$tenant,$this->updateableColumns);  	

// store the model if it has changed
    	try {
    		if ($tenant->isDirty()) {
    			$tenant->save();
    		}

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($tenant, 200);
    }   

/**
 * Delete tenant instance
 * @param  Tenant
 * @return [type]
 */
    public function delete(Tenant $tenant) {

// Don't allow deletion of default tenant

        if ($tenant->pkey == 'default') {
           return Response::json(['Error - Cannot delete default tenant!'],409); 
        }

        $tenant->delete();

        return response()->json(['tenant ' .$tenant->id .' deleted'],200);
    }
    //
}
