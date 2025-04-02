<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{

	private $updateableColumns = [
    		'abstimeout' => 'integer',
			'allow_hash_xfer' => 'in:enabled,disabled',
			'callrecord1' => 'in:None,In,Out,Both',
			'cfwdextern_rule' => 'In:YES,NO',
			'cfwd_progress' => 'in:enabled,disabled',
			'cfwd_answer' => 'in:enabled,disabled',
			'clusterclid' => 'integer|nullable',
			'chanmax' => 'integer',
			'countrycode' => 'integer',
			'dynamicfeatures' => 'string',
			'description' => 'string',
			'emergency' => 'integer',
			'int_ring_delay' => 'integer',
			'ivr_key_wait' => 'integer',
			'ivr_digit_wait' => 'integer',
			'language' => 'string',
			'ldapanonbind' => 'YES',
			'ldapbase' => 'string',
			'ldaphost' => 'string',
			'ldapou' => 'string',
			'ldapuser' => 'string',
			'ldappass' => 'pbx3string',
			'ldaptls' => 'in:on,off',
			'localarea' => 'numeric|nullable',
			'localdplan' => [
					'regex:/^_X+$/',
					'nullable'
			],
			'lterm' => 'boolean',
			'leasedhdtime' => 'integer|nullable',
			'masteroclo' => 'in:AUTO,CLOSED',
			'max_in' => 'integer',
			'monitor_out' => 'string',
			'operator' => 'integer',
			'pickupgroup' => 'string',
			'play_beep' => 'boolean',
			'play_busy' => 'boolean',
			'play_congested' => 'boolean',
			'play_transfer' => 'boolean',
			'rec_age' => 'integer',
			'rec_final_dest' => 'string',
			'rec_file_dlim' => 'string',
			'rec_grace' => 'integer',
			'rec_limit' => 'integer',
			'rec_mount' => 'integer',
			'recmaxage' => 'integer',
			'recmaxsize' => 'integer',
			'recused' => 'integer',
			'ringdelay' => 'integer',
			'routeoverride' => 'integer',
			'spy_pass' => 'integer',
			'sysop' => 'integer',
			'syspass' => 'integer',
			'usemohcustom' => 'integer|nullable',
			'vmail_age' => 'integer',
			'voice_instr' => 'boolean',
			'voip_max' => 'integer'
    	];

    //
/**
 * Return Tenant Index in pkey order asc
 * 
 * @return Tenants
 */
    public function index () {

    	return Tenant::orderBy('pkey','asc')->get();
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

    	$this->updateableColumns['pkey'] = 'required';
    	$this->updateableColumns['description'] = 'string|required';

    	$validator = Validator::make($request->all(),$this->updateableColumns); 

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        if (Tenant::where('pkey','=',$request->pkey)->count()) {
           return Response::json(['Error' => 'Key already exists'],409); 
        }

    	$tenant = new Tenant;
		$tenant->id = trim(`ksuid`);

// Move post variables to the model 

    	move_request_to_model($request,$tenant,$this->updateableColumns); 

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
