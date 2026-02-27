<?php

namespace App\Http\Controllers;

use App\Models\Trunk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TrunkController extends Controller
{
    //
	// trunks table (sqlite_create_instance.sql). Only columns that exist in DB; exclude id, shortuid, z_*. Updateable set from TRUNK_AUDIT_PROTOTYPE.md.
    	private $updateableColumns = [
    		'pkey' => 'string|nullable',
    		'active' => 'in:YES,NO',
			'alertinfo' => 'string|nullable',
			'callback' => 'string|nullable',
			'callerid' => 'string|nullable',
			'callprogress' => 'in:YES,NO',
			'closeroute' => 'string|nullable',
			'cluster' => 'exists:cluster,pkey',
			'cname' => 'string|nullable',
			'description' => 'string|nullable',
			'devicerec' => 'in:None,OTR,OTRR,Inbound,Outbound,Both',
			'disa' => 'in:DISA,CALLBACK|nullable',
			'disapass' => 'string|nullable',
			'host' => 'string|nullable',
			'iaxreg' => 'string|nullable',
			'inprefix' => 'string|nullable',
			'match' => 'string|nullable',
			'moh' => 'in:YES,NO',
			'openroute' => 'string|nullable',
			'password' => 'string|nullable',
			'peername' => 'string|nullable',
			'pjsipreg' => 'string|nullable',
			'privileged' => 'string|nullable',
			'register' => 'string|nullable',
			'swoclip' => 'in:YES,NO',
			'tag' => 'string|nullable',
			'technology' => 'in:SIP,IAX2|nullable',
			'transform' => 'string|nullable',
			'transport' => 'in:udp,tcp,tls,wss',
			'trunkname' => 'string|nullable',
			'username' => 'string|nullable',
    	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 * Return Trunk index in pkey order asc.
 * Instance schema uses trunks table only (DDI/CLID are in inroutes).
 *
 * @return \Illuminate\Support\Collection
 */
    public function index () {

    	return Trunk::where('technology', '=', 'SIP')
    		->orWhere ('technology', '=', 'IAX2' )
    		->orderBy('pkey','asc')->get();
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (Trunk $trunk) {

    	return $trunk;
    }

/**
 * Create a new Trunk instance
 * Uses Request (not TrunkRequest) so route('trunk') is never invoked on POST;
 * TrunkRequest references route('trunk') and can trigger ReflectionClass error when no {trunk} param exists.
 *
 * @param  Request
 * @return New Trunk
 */
    public function save(Request $request) {

		// First cut: all new trunks belong to the default tenant (TRUNK_ROUTE_MULTITENANCY)
		$request->merge(['cluster' => 'default']);
		$clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
		if ($clusterShortuid === null) {
			return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
		}

// validation (technology set by user on create; no Carrier table)
  		$this->updateableColumns['pkey'] = 'required';
		$this->updateableColumns['technology'] = 'required|in:SIP,IAX2';
		$this->updateableColumns['cluster'] = 'required|exists:cluster,pkey';
		$this->updateableColumns['username'] = 'required';
		$this->updateableColumns['host'] = 'required';

    	$validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
//Check if key exists within tenant (cluster); DB stores shortuid
            if (Trunk::where('pkey','=',$request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey . " in this tenant.");
                return;
            }                 
        });  

        if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

    	$trunk = new Trunk;

    	move_request_to_model($request,$trunk,$this->updateableColumns);
		$trunk->cluster = $clusterShortuid;

// Populate id (ksuid) and shortuid via helpers; both in tenant schema (sqlite_create_tenant.sql) and persisted
    	$trunk->id = generate_ksuid();
    	$trunk->shortuid = generate_shortuid();

//  peername = username unless overriden by caller
    	if (empty($trunk->peername)) {
    		$trunk->peername = $trunk->username;
    	}

// trunkname = peername unless overridden by caller
    	if (empty($trunk->trunkname)) {
    		$trunk->trunkname = $trunk->peername;
    	}

		// Technology set from request (dropdown: SIP | IAX2); default SIP if missing
		$trunk->technology = $request->input('technology', 'SIP');

		// Omit request-only attributes not in trunks table (no Carrier table)
		$omitFromInsert = [ 'carrier', 'sipiaxpeer', 'sipiaxuser' ];
		foreach ($omitFromInsert as $key) {
			$trunk->offsetUnset($key);
		}

// create the model
    	try {

    		$trunk->save();

    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

    	return $trunk;
	}



/**
 * @param  Request
 * @param  Trunk
 * @return json response
 */
    public function update(Request $request, Trunk $trunk) {

		// First cut: trunk tenant is not changeable; force default (TRUNK_ROUTE_MULTITENANCY). When layered permissions are added, users with the right role may be allowed to modify trunk tenant (later phase).
		$request->merge(['cluster' => 'default']);

// Validate (Request + Validator only; no Form Request)
    	$validator = Validator::make($request->all(), $this->updateableColumns);

    	$validator->after(function ($validator) use ($request, $trunk) {
			$host = $request->host;
			if ($host && strcasecmp($host, 'dynamic') !== 0 && ! valid_ip_or_domain($host)) {
				$validator->errors()->add('host', "Host must be valid IP, valid domain name, or 'dynamic': " . $host);
			}
			// pkey uniqueness when client sends a different pkey
			$pkeySubmitted = $request->input('pkey');
			if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $trunk->getAttribute('pkey')) {
				$clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
				if ($clusterShortuid !== null && Trunk::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $trunk->id)->exists()) {
					$validator->errors()->add('pkey', 'That name is already in use in this tenant.');
				}
			}
		});

		if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

// Move post variables to the model   
		move_request_to_model($request,$trunk,$this->updateableColumns);
		$clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
		if ($clusterShortuid !== null) {
			$trunk->cluster = $clusterShortuid;
		}

// store the model if it has changed — update by id only (tenant-safe)
    	try {
    		if ($trunk->isDirty()) {
    			$id = $trunk->id;
    			if ($id === null || $id === '') {
    				return Response::json(['Error' => 'Trunk id is missing'], 409);
    			}
    			$dirty = $trunk->getDirty();
    			Trunk::where('id', $id)->update($dirty);
    			$trunk->syncOriginal();
    		}
        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($trunk, 200);
		
    } 


/**
 * Delete  Extension instance
 * @param  Extension
 * @return NULL
 */
    public function delete(Trunk $trunk) {
        $trunk->delete();

        return response()->json(null, 204);
    }
}
