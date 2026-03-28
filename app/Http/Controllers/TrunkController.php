<?php

namespace App\Http\Controllers;

use App\Models\Trunk;
use Barryvdh\DomPDF\Facade\Pdf;
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

    /** Export trunks list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $trunks = Trunk::where('technology', '=', 'SIP')
            ->orWhere('technology', '=', 'IAX2')
            ->orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($trunks);
        return Pdf::loadView('exports.trunks-pdf', ['trunks' => $trunks])
            ->setPaper('a4', 'landscape')
            ->download('trunks.pdf');
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

		$this->normalizeTrunkPjsipregOnWrite($request, null);

// validation (technology from user; SIP or IAX2; no Carrier table)
		$createRules = array_merge($this->updateableColumns, [
			'pkey' => 'required|string',
			'technology' => 'required|in:SIP,IAX2',
			'cluster' => 'required|exists:cluster,pkey',
			'username' => 'required',
			'host' => 'required',
			// Create-only: not in updateableColumns so PUT cannot change it (see normalizeTrunkPjsipregOnWrite).
			'pjsipreg' => 'nullable|in:SND,RCV',
		]);

    	$validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            if (Trunk::where('pkey', '=', $request->input('pkey'))->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'Duplicate name in this tenant.');
                return;
            }
			$this->validateTrunkCreatePjsipregAndHost($validator, $request);
        });  

        if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

    	$trunk = new Trunk;

    	move_request_to_model($request, $trunk, $createRules);
		$trunk->cluster = $clusterShortuid;
		$trunk->technology = $request->input('technology', 'SIP');

// Populate id (ksuid) and shortuid via helpers
    	$trunk->id = generate_ksuid();
    	$trunk->shortuid = generate_shortuid();

		if (empty($trunk->peername)) {
    		$trunk->peername = $trunk->username;
    	}
    	if (empty($trunk->trunkname)) {
    		$trunk->trunkname = $trunk->peername;
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

		// SIP registration mode (pjsipreg) is create-only; strip so PUT cannot change it (IAX2 still clears via normalize).
		$request->request->remove('pjsipreg');

		$this->normalizeTrunkPjsipregOnWrite($request, $trunk);

// Validate (Request + Validator only; no Form Request)
    	$validator = Validator::make($request->all(), $this->updateableColumns);

    	$validator->after(function ($validator) use ($request, $trunk) {
			$host = $request->host;
			if ($host && strcasecmp($host, 'dynamic') !== 0 && ! valid_ip_or_domain($host)) {
				$validator->errors()->add('host', "Host must be valid IP, valid domain name, or 'dynamic': " . $host);
			}
			$this->validateTrunkUpdatePjsipreg($validator, $request, $trunk);
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
        $pkey = (string) $trunk->getAttribute('pkey');
        $trunk->delete();
        pbx3_delete_trunk_asterisk_instances($pkey);
        set_commit_dirty();

        return response()->json(null, 204);
    }

	/**
	 * Normalize pjsipreg for writes: IAX2 clears it; SIP accepts SND, RCV, NONE/empty → null (trusted).
	 * RCV forces host=dynamic. Values uppercased for PBX generator (HelperClass switch).
	 */
	private function normalizeTrunkPjsipregOnWrite(Request $request, ?Trunk $existing): void
	{
		$technology = $request->input('technology');
		if ($technology === null || $technology === '') {
			$technology = $existing?->technology;
		}
		if ($technology === 'IAX2') {
			$request->merge(['pjsipreg' => null]);

			return;
		}
		if ($technology !== 'SIP') {
			return;
		}
		// Update: do not overwrite pjsipreg when the client omits it (partial PUT).
		if ($existing !== null && ! $request->has('pjsipreg')) {
			return;
		}
		$raw = $request->input('pjsipreg');
		if ($raw === null || $raw === '') {
			$request->merge(['pjsipreg' => null]);
		} else {
			$up = strtoupper(trim((string) $raw));
			if ($up === 'NONE' || $up === 'NULL') {
				$request->merge(['pjsipreg' => null]);
			} elseif (in_array($up, ['SND', 'RCV'], true)) {
				$request->merge(['pjsipreg' => $up]);
			} else {
				$request->merge(['pjsipreg' => $up]);
			}
		}
		if ($request->input('pjsipreg') === 'RCV') {
			$request->merge(['host' => 'dynamic']);
		}
	}

	private function validateTrunkCreatePjsipregAndHost(\Illuminate\Validation\Validator $validator, Request $request): void
	{
		if ($request->input('technology') !== 'SIP') {
			return;
		}
		$mode = $request->input('pjsipreg');
		$password = $request->input('password');
		$hasPassword = $password !== null && $password !== '' && trim((string) $password) !== '';

		if ($mode === 'SND' || $mode === 'RCV') {
			if (! $hasPassword) {
				$validator->errors()->add('password', 'Password is required for SIP registration (send or accept).');
			}
		}

		$host = $request->input('host');
		if ($mode === 'SND') {
			if ($host && strcasecmp((string) $host, 'dynamic') === 0) {
				$validator->errors()->add('host', 'Use “Trusted peer” or “Accept registration” instead of send-registration with host “dynamic”.');
			} elseif ($host && strcasecmp((string) $host, 'dynamic') !== 0 && ! valid_ip_or_domain((string) $host)) {
				$validator->errors()->add('host', "Host must be a valid IP or domain for send-registration: {$host}");
			}
		} elseif ($mode === null || $mode === '') {
			if ($host && strcasecmp((string) $host, 'dynamic') === 0) {
				$validator->errors()->add('host', 'For host “dynamic”, set SIP registration to “Accept registration” (RCV).');
			} elseif ($host && ! valid_ip_or_domain((string) $host)) {
				$validator->errors()->add('host', "Host must be valid IP or domain: {$host}");
			}
		}
	}

	private function validateTrunkUpdatePjsipreg(\Illuminate\Validation\Validator $validator, Request $request, Trunk $trunk): void
	{
		$technology = $request->has('technology') ? $request->input('technology') : $trunk->technology;
		if ($technology === 'IAX2') {
			return;
		}
		if ($technology !== 'SIP') {
			return;
		}
		// pjsipreg is not mutable on update (create-only).
		$mode = $trunk->pjsipreg;
		if ($mode !== 'SND' && $mode !== 'RCV') {
			$mode = null;
		}
		$password = $request->input('password');
		$passwordInRequest = $request->has('password');
		$hasNewPassword = $passwordInRequest && $password !== null && trim((string) $password) !== '';

		if (($mode === 'SND' || $mode === 'RCV') && $passwordInRequest && ! $hasNewPassword) {
			$existing = $trunk->getAttribute('password');
			if ($existing === null || $existing === '') {
				$validator->errors()->add('password', 'Password is required for SIP registration (send or accept).');
			}
		}

		$host = $request->has('host') ? $request->input('host') : $trunk->host;
		if ($mode === 'SND' && $host && strcasecmp((string) $host, 'dynamic') === 0) {
			$validator->errors()->add('host', 'Send-registration cannot use host “dynamic”.');
		}
	}
}
