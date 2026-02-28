<?php

namespace App\Http\Controllers;

use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Models\IpPhoneCosOpen;
use App\Models\IpPhoneCosClosed;
use App\Models\Cos;
use App\CustomClasses\Ami;
use App\Http\Requests\ExtensionRequest;

class ExtensionController extends Controller
{

	// ipphone table (full_schema.sql). Exclude id, pkey, shortuid, z_*. Model guarded: abstimeout, basemacaddr, devicemodel, passwd, etc.
	private $updateableColumns = [
		'active' => 'in:YES,NO',
		'callbackto' => 'in:desk,cell',
		'callerid' => 'string|nullable',
		'callmax' => 'integer|nullable',
		'cellphone' => 'string|nullable',
		'celltwin' => 'in:ON,OFF',
		'cluster' => 'exists:cluster,pkey',
		'cname' => 'string|nullable',
		'desc' => 'nullable|string|max:255',
		'description' => 'string|nullable',
		'device' => 'string|nullable',
		'devicerec' => 'in:default,None,Inbound,Outbound,Both',
		'dvrvmail' => 'exists:ipphone,pkey|nullable',
		'extalert' => 'string|nullable',
		'macaddr' => 'string|nullable',
		'protocol' => 'in:IPV4,IPV6',
		'provision' => 'string|nullable',
		'provisionwith' => 'in:IP,FQDN',
		'pjsipuser' => 'string|nullable',
		'technology' => 'string|nullable',
		'transport' => 'in:udp,tcp,tls,wss',
		'vmailfwd' => 'email|nullable',
	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**
 * Return Extension Index in pkey order asc.
 * Each extension includes tenant_pkey (cluster pkey for display) resolved from cluster table.
 *
 * @return Extensions
 */
    public function index () {

    	$extensions = Extension::orderBy('pkey','asc')->get();

    	// Build cluster id/shortuid/pkey -> tenant pkey map (id = KSUID, shortuid = 8-char, pkey = human-facing)
    	$clusterToPkey = [];
    	try {
    		$rows = DB::table('cluster')->get(['id', 'shortuid', 'pkey']);
    		foreach ($rows as $row) {
    			if (isset($row->id)) {
    				$clusterToPkey[(string) $row->id] = $row->pkey ?? $row->id;
    			}
    			if (isset($row->shortuid)) {
    				$clusterToPkey[(string) $row->shortuid] = $row->pkey ?? $row->shortuid;
    			}
    			if (isset($row->pkey)) {
    				$clusterToPkey[(string) $row->pkey] = $row->pkey;
    			}
    		}
    	} catch (\Throwable $e) {
    		try {
    			$rows = DB::table('cluster')->get(['id', 'pkey']);
    			foreach ($rows as $row) {
    				if (isset($row->id)) {
    					$clusterToPkey[(string) $row->id] = $row->pkey ?? $row->id;
    				}
    				if (isset($row->pkey)) {
    					$clusterToPkey[(string) $row->pkey] = $row->pkey;
    				}
    			}
    		} catch (\Throwable $e2) {
    			$rows = DB::table('cluster')->get(['pkey']);
    			foreach ($rows as $row) {
    				if (isset($row->pkey)) {
    					$clusterToPkey[(string) $row->pkey] = $row->pkey;
    				}
    			}
    		}
    	}

    	foreach ($extensions as $ext) {
    		$cluster = $ext->cluster ?? null;
    		$ext->tenant_pkey = $cluster !== null && $cluster !== ''
    			? ($clusterToPkey[(string) $cluster] ?? $cluster)
    			: $cluster;
    	}
    	return $extensions;
    }

/**
 * Return live PJSIP data (IP, latency) for all active SIP extensions.
 * Keyed by pkey for merging with list view. Requires Asterisk running.
 *
 * @return object keyed by extension pkey, values { ip, latency }
 */
    public function indexLive() {
        set_time_limit(30);
        if (!function_exists('pbx_is_running') || !pbx_is_running()) {
            return Response::json(['message' => 'PBX not running'], 503);
        }
        $extensions = Extension::where('technology', 'SIP')
            ->orderBy('pkey')
            ->limit(200)
            ->get(['pkey', 'shortuid']);
        $live = [];
        try {
            $amiHandle = get_ami_handle();
            foreach ($extensions as $ext) {
                $endpointId = $ext->shortuid ?? $ext->pkey;
                $live[$ext->pkey] = pjsip_endpoint_live($amiHandle, $endpointId);
            }
            $amiHandle->logout();
        } catch (\Throwable $e) {
            Log::warning('Extensions live data failed', ['error' => $e->getMessage()]);
            return Response::json(['message' => 'Could not fetch live endpoint data'], 503);
        }
        return Response::json($live, 200);
    }

/**
 * Create a new extension (single endpoint). extensionType: SIP | WebRTC.
 * Sets id (ksuid), dvrvmail = pkey. Device/provision from Device table and optional MAC.
 *
 * @param  Request  pkey, cluster, desc (name), extensionType (SIP|WebRTC), macaddr (optional for SIP), protocol (IPV4|IPV6)
 * @return New extension
 */
    public function save(Request $request) {
        $all = $request->all();
        $extensionTypeInput = $all['extensionType'] ?? null;
        if (!$extensionTypeInput && isset($all['protocol']) && in_array($all['protocol'], ['SIP', 'WebRTC'], true)) {
            $extensionTypeInput = $all['protocol'];
        }
        // Normalise for validation: if client sent protocol=SIP|WebRTC (old frontend), use ipversion for protocol (IP version)
        $validateInput = array_merge($all, ['extensionType' => $extensionTypeInput]);
        if (isset($validateInput['protocol']) && in_array($validateInput['protocol'], ['SIP', 'WebRTC'], true)) {
            $validateInput['protocol'] = $all['ipversion'] ?? 'IPV4';
        }
        $validator = Validator::make($validateInput, [
            'pkey' => 'required',
            'cluster' => 'required|exists:cluster,pkey',
            'desc' => 'nullable|string|max:255',
            'extensionType' => 'required|in:SIP,WebRTC',
            'macaddr' => 'nullable|regex:/^[0-9a-fA-F]{12}$/',
            'active' => 'nullable|in:YES,NO',
            'transport' => 'nullable|in:udp,tcp,tls,wss',
            'callbackto' => 'nullable|in:desk,cell',
            'callerid' => 'nullable|string|max:255',
            'cellphone' => 'nullable|string|max:255',
            'celltwin' => 'nullable|in:ON,OFF',
            'devicerec' => 'nullable|in:default,None,Inbound,Outbound,Both',
            'protocol' => 'nullable|in:IPV4,IPV6',
            'ipversion' => 'nullable|in:IPV4,IPV6',
            'vmailfwd' => 'nullable|email',
        ]);

        $validator->after(function ($validator) use ($request, $extensionTypeInput) {
            $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
            if ($clusterShortuid === null && $request->cluster !== null && $request->cluster !== '') {
                $validator->errors()->add('cluster', 'Invalid cluster.');
            }
            if ($clusterShortuid !== null && Extension::where('pkey', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('save', 'Duplicate extension - ' . $request->pkey . ' in this tenant.');
            }
            if ($extensionTypeInput === 'SIP' && $request->macaddr) {
                $mac = preg_replace('/[^0-9a-fA-F]/', '', $request->macaddr);
                if ($mac !== '' && Extension::where('macaddr', $mac)->exists()) {
                    $validator->errors()->add('macaddr', 'This MAC already exists.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $pkey = $request->input('pkey');
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
        $desc = $request->input('desc');
        $extensionType = $request->input('extensionType') ?: $extensionTypeInput;
        $macaddr = $request->input('macaddr');
        $macaddr = $macaddr !== null && $macaddr !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $macaddr) : null;
        $protocolInput = $request->input('protocol');
        if (!in_array($protocolInput, ['IPV4', 'IPV6'], true)) {
            $protocolInput = $request->input('ipversion', 'IPV4');
        }

        $id = generate_ksuid();
        $shortuid = generate_shortuid();
        $dvrvmail = $pkey;

        $attrs = [
            'id' => $id,
            'shortuid' => $shortuid,
            'pkey' => $pkey,
            'cluster' => $clusterShortuid,
            'dvrvmail' => $dvrvmail,
        ];

        $provisionwith = 'IP';
        try {
            $globals = get_globals();
            if ($globals && isset($globals->fqdnprov) && strtoupper((string) $globals->fqdnprov) === 'YES') {
                $provisionwith = 'FQDN';
            }
        } catch (\Throwable $e) {
            // keep default IP
        }
        $attrs['provisionwith'] = $provisionwith;

        if ($extensionType === 'SIP') {
            $attrs['desc'] = $desc ?: ('Ext' . $pkey);
            $attrs['transport'] = $request->input('transport', 'udp');
            $attrs['protocol'] = $protocolInput;

            if ($macaddr !== null && $macaddr !== '') {
                $deviceVendor = $this->getVendorFromMac($macaddr);
                if ($deviceVendor === null) {
                    return response()->json(['macaddr' => ["Can't find Manufacturer for this MAC."]], 422);
                }
                $attrs['macaddr'] = $macaddr;
                $attrs['device'] = $deviceVendor;
                $deviceRow = $this->getDeviceRow($deviceVendor);
                if ($deviceRow) {
                    $attrs['pjsipuser'] = $deviceRow->sipiaxfriend ?? null;
                    $attrs['technology'] = $deviceRow->technology ?? 'SIP';
                }
                $provision = '#INCLUDE ' . $deviceVendor;
                if (preg_match('/^[Cc]isco/', $deviceVendor)) {
                    $provision .= "\n</flat-profile>\n</device>";
                }
                $attrs['provision'] = $provision;
            } else {
                $attrs['device'] = 'General SIP';
                $deviceRow = $this->getDeviceRow('General SIP');
                if ($deviceRow) {
                    $attrs['pjsipuser'] = $deviceRow->sipiaxfriend ?? null;
                    $attrs['technology'] = $deviceRow->technology ?? 'SIP';
                }
                $attrs['provision'] = null;
            }
        } else {
            $attrs['desc'] = $desc ?: ('Ext' . $pkey);
            $attrs['device'] = 'WebRTC';
            $attrs['transport'] = $request->input('transport', 'wss');
            $attrs['protocol'] = $protocolInput;
            $deviceRow = $this->getDeviceRow('WebRTC');
            if ($deviceRow) {
                $attrs['pjsipuser'] = $deviceRow->sipiaxfriend ?? null;
                $attrs['technology'] = $deviceRow->technology ?? 'SIP';
            }
            $attrs['provision'] = null;
        }

        if ($request->has('active')) {
            $attrs['active'] = $request->input('active');
        }
        if ($request->has('callbackto')) {
            $attrs['callbackto'] = $request->input('callbackto');
        }
        if ($request->has('callerid')) {
            $attrs['callerid'] = $request->input('callerid') ?: null;
        }
        if ($request->has('cellphone')) {
            $attrs['cellphone'] = $request->input('cellphone') ?: null;
        }
        if ($request->has('celltwin')) {
            $attrs['celltwin'] = $request->input('celltwin');
        }
        if ($request->has('devicerec')) {
            $attrs['devicerec'] = $request->input('devicerec');
        }
        if ($request->has('vmailfwd')) {
            $attrs['vmailfwd'] = $request->input('vmailfwd') ?: null;
        }

        try {
            $extension = Extension::create($attrs);
        } catch (\Exception $e) {
            Log::warning('Extension create failed', ['error' => $e->getMessage(), 'attrs_keys' => array_keys($attrs)]);
            return response()->json([
                'Error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 409);
        }

        if ($extension->provision !== null && $extension->provision !== '') {
            $this->adjustAstProvSettings($extension);
            Extension::where('id', $extension->id)->update(['provision' => $extension->provision]);
        }

        $this->create_default_cos_instances($extension);

        set_commit_dirty();

        return response()->json($extension->fresh(), 201);
    }

    /** Get Device row by pkey (instance schema). */
    private function getDeviceRow(string $devicePkey) {
        try {
            return DB::table('device')->where('pkey', $devicePkey)->first(['sipiaxfriend', 'technology']);
        } catch (\Throwable $e) {
            return null;
        }
    }

/**
 * Return named extension instance. Resolves cluster to tenant_pkey for display.
 *
 * @param  Extension
 * @return extension object
 */
    public function show (Extension $extension) {

    	$cluster = $extension->cluster ?? null;
    	if ($cluster !== null && $cluster !== '') {
    		$row = DB::table('cluster')->where('pkey', $cluster)->orWhere('shortuid', $cluster)->orWhere('id', $cluster)->first(['pkey']);
    		$extension->tenant_pkey = $row ? $row->pkey : $cluster;
    	} else {
    		$extension->tenant_pkey = $cluster;
    	}
    	return $extension;
    }

/**
 * Return named extension runtime values from the PBX (CFIM, CFBS, ringdelay; for SIP also ip and latency).
 *
 * @param  Extension
 * @return object cfim, cfbs, ringdelay; for SIP extensions also ip, latency
 */
    public function showruntime (Extension $extension) {

        $amiHandle = get_ami_handle();

        $rets = [];
        $rets['cfim'] = $amiHandle->GetDB('cfim', $extension->pkey);
        $rets['cfbs'] = $amiHandle->GetDB('cfbs', $extension->pkey);
        $rets['ringdelay'] = $amiHandle->GetDB('ringdelay', $extension->pkey);

        if (($extension->technology ?? '') === 'SIP') {
            $live = pjsip_endpoint_live($amiHandle, $extension->shortuid ?? $extension->pkey);
            $rets['ip'] = $live['ip'];
            $rets['latency'] = $live['latency'];
        }

        $amiHandle->logout();

        return Response::json($rets, 200);
    }    

/**
 * Create a new MAILBOX extension instance
 * 
 * @param  Request
 * @return New extension
 */
    public function mailbox(Request $request) {
        $validator = Validator::make($request->all(), [
            'pkey' => 'required|unique:ipphone,pkey',
            'cluster' => 'required|exists:cluster,pkey',
            'desc' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
    	
    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey'
    	]);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });        

    	$clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
    	if ($clusterShortuid === null) {
    		return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
    	}
    	try {
    		$extension = Extension::create([
    			'id' => generate_ksuid(),
    			'shortuid' => generate_shortuid(),
    			'pkey' => $request->post('pkey'),
    			'desc' => 'MAILBOX',
    			'device' => 'MAILBOX',
    			'cluster' => $clusterShortuid,
    			]);
    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}
    	return $extension;
	}


/**
 * Create a new unprovisioned extension instance
 * 
 * @param  Request
 * @return New Unprovisioned Extension
 */

    public function unprovisioned(Request $request) {

    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey'
    	]);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

        $clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }
    	try {
    		$extension = Extension::create([
    			'id' => generate_ksuid(),
    			'shortuid' => generate_shortuid(),
    			'pkey' => $request->post('pkey'),
    			'desc' => 'Ext' .$request->post('pkey'),
    			'device' => 'General SIP',
    			'cluster' => $clusterShortuid,
    			]);
    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

// create default Clsss of service contraints

    	$this->create_default_cos_instances($extension);

    	return $extension;
	}

/**
 * Create a new webrtc extension instance
 * 
 * @param  Request
 * @return New webrtc Extension
 */

 public function webrtc(Request $request) {

	$validator = Validator::make($request->all(),[
		'pkey' => 'required',
		'cluster' => 'required|exists:cluster,pkey'
	]);

	$validator->after(function ($validator) use ($request) {
//Check if key exists
		if (Extension::where('pkey','=',$request->pkey)->count()) {
			$validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
			return;
		}                 
	});

	if ($validator->fails()) {
		return response()->json($validator->errors(),422);
	}

	$clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
	if ($clusterShortuid === null) {
		return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
	}

	try {
		$extension = Extension::create([
			'id' => generate_ksuid(),
			'shortuid' => generate_shortuid(),
			'pkey' => $request->post('pkey'),
			'desc' => 'Ext' .$request->post('pkey'),
			'device' => 'WebRTC',
			'transport' => 'wss',
			'cluster' => $clusterShortuid,
			]);
	} catch (\Exception $e) {
		return Response::json(['Error' => $e->getMessage()],409);
	}

// create default Class of service contraints

	$this->create_default_cos_instances($extension);

	return $extension;
}	

/**
 * Create a new provisioned extension instance
 * 
 * @param  Request
 * @return New provisioned Extension
 */

	public function provisioned(Request $request) {

    	$validator = Validator::make($request->all(),[
    		'pkey' => 'required',
    		'cluster' => 'required|exists:cluster,pkey',
    		'macaddr' => 'required|regex:/^[0-9a-fA-F]{12}$/'
    	]);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Extension::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

    	$device=null;

		if ($request->post('macaddr')) {  
			$device = $this->getVendorFromMac($request->post('macaddr')); 
		} 

    	$validator->after(function ($validator) use ($request, $device) {
			// Lookup the vendor from the MAC address
		
    		if (! isset($device)) {
        		$validator->errors()->add('macaddr', "Can't find Manufacturer for this MAC! " . $request->post('macaddr'));
    		}
    		else {
    			// check for duplicate MAC
    			if (Extension::where('macaddr','=',$request->post('macaddr'))->count()) {
    				$validator->errors()->add('macaddr', "This MAC already exists in the DB! " . $request->post('macaddr'));
    			}    			
    		}
		});

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

    	// Set initial provisioning string
    	
    	$provision = "#INCLUDE " .  $device . "\n";
    	$provision .= "#INCLUDE " .  $device . '.udp' . "\n";
    	$provision .= "#INCLUDE " .  $device . '.ipv4' . "\n";

        $clusterShortuid = cluster_identifier_to_shortuid($request->post('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

    	// store it

    	try {
        	$extension = Extension::create([
        		'id' => generate_ksuid(),
        		'shortuid' => generate_shortuid(),
        		'pkey' => $request->post('pkey'),
        		'provision' => $provision,
        		'device' => $device,
        		'cluster' => $clusterShortuid,
        		'macaddr' => $request->post('macaddr'),
        		]);
        } catch (\Exception $e) {
   			return Response::json(['Error' => $e->getMessage()],409);
    	}

// create default Clsss of service contraints

    	$this->create_default_cos_instances($extension);

		return response()->json($extension, 201);
		
    }  

    /**
     * @param  ExtensionRequest $request
     * @param  Extension $extension
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(ExtensionRequest $request, Extension $extension) {
        $originalMac = $extension->getOriginal('macaddr');
        $originalMac = $originalMac !== null && $originalMac !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $originalMac) : null;

        foreach ($request->all() as $key => $value) {
            if (array_key_exists($key, $this->updateableColumns)) {
                if ($key === 'cluster') {
                    $extension->cluster = cluster_identifier_to_shortuid($value) ?? trim((string) $value);
                } else {
                    $extension->$key = is_string($value) ? trim($value) : $value;
                }
            }
        }

        $newMac = $extension->macaddr;
        $newMac = $newMac !== null && $newMac !== '' ? preg_replace('/[^0-9a-fA-F]/', '', $newMac) : null;

        $macAdded = $originalMac === null && $newMac !== null;
        $macChanged = $originalMac !== null && $newMac !== null && $originalMac !== $newMac;
        $macRemoved = $originalMac !== null && $newMac === null;

        if ($macAdded || $macChanged) {
            if (strlen($newMac) !== 12 || !preg_match('/^[0-9a-fA-F]{12}$/', $newMac)) {
                return response()->json(['macaddr' => ['MAC must be 12 hex characters.']], 422);
            }
            $exists = Extension::where('macaddr', $newMac)->where('id', '!=', $extension->id)->exists();
            if ($exists) {
                return response()->json(['macaddr' => ['This MAC already exists.']], 422);
            }
            $deviceVendor = $this->getVendorFromMac($newMac);
            if ($deviceVendor === null) {
                return response()->json(['macaddr' => ["Can't find Manufacturer for this MAC."]], 422);
            }
            $extension->macaddr = $newMac;
            $extension->device = $deviceVendor;
            $deviceRow = $this->getDeviceRow($deviceVendor);
            if ($deviceRow) {
                $extension->pjsipuser = $deviceRow->sipiaxfriend ?? null;
                $extension->technology = $deviceRow->technology ?? 'SIP';
            }
            $provision = '#INCLUDE ' . $deviceVendor;
            if (preg_match('/^[Cc]isco/', $deviceVendor)) {
                $provision .= "\n</flat-profile>\n</device>";
            }
            $extension->provision = $provision;
            $this->adjustAstProvSettings($extension);
        } elseif ($macRemoved) {
            $extension->device = 'General SIP';
            $extension->provision = null;
            $extension->pjsipuser = null;
            $deviceRow = $this->getDeviceRow('General SIP');
            if ($deviceRow) {
                $extension->pjsipuser = $deviceRow->sipiaxfriend ?? null;
                $extension->technology = $deviceRow->technology ?? 'SIP';
            }
        } elseif (($extension->isDirty('transport') || $extension->isDirty('protocol')) && $extension->provision !== null && trim((string) $extension->provision) !== '') {
            $this->adjustAstProvSettings($extension);
        }

        try {
            if ($extension->isDirty()) {
                $id = $extension->id;
                if ($id === null || $id === '') {
                    return response()->json(['Error' => 'Extension id is missing'], 409);
                }
                $dirty = $extension->getDirty();
                Extension::where('id', $id)->update($dirty);
                $extension->syncOriginal();
                set_commit_dirty();
            }
        } catch (\Exception $e) {
            return response()->json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($extension->fresh(), 200);
    } 

/**
 * Return named extension runtime from the PBX
 * 
 * @param  Extension
 * @return extension object
 */
    public function updateruntime (Request $request, Extension $extension) {

        $validator = Validator::make($request->all(),[
            'cfim' => 
                ['regex:/^\+?\d+$/'],
                ['nullable'],
            'cfbs' => 
                ['regex:/^\+?\d+$/'],
                ['nullable'],            
            'ringdelay' => 'integer|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        $amiHandle = get_ami_handle();

        if (isset($request->cfim)) {
           $amiHandle->PutDB('cfim', $extension->pkey, $request->cfim); 
        }

        if ($request->cfbs) {
           $amiHandle->PutDB('cfbs', $extension->pkey, $request->cfbs); 
        }        

        if ($request->ringdelay) {
           $amiHandle->PutDB('ringdelay', $extension->pkey, $request->ringdelay); 
        }

        $amiHandle->logout();

        return Response::json(null,204);
    }        

/**
 * Delete  Extension instance
 * @param  Extension
 * @return NULL
 */
    public function delete(Extension $extension) {

        // Delete related rows only if tables exist; missing tables must not block extension delete
        try {
            IpPhoneCosOpen::where('ipphone_pkey', $extension->pkey)->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }
        try {
            IpPhoneCosClosed::where('ipphone_pkey', $extension->pkey)->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }

        $extension->delete();

        set_commit_dirty();

        return response()->json(null, 204);
    }
 


/**
 * Search the vendor database for the OUI of a given MAC address.
 *
 * @param  string $mac 12 hex chars (no colons)
 * @return string|null short vendor name, e.g. Yealink, or null if not found
 */
    private function getVendorFromMac($mac) {
        $shortmac = strtoupper(preg_replace('/[^0-9A-F]/', '', substr($mac, 0, 6)));
        if (strlen($shortmac) !== 6 || !preg_match('/^[0-9A-F]{6}$/', $shortmac)) {
            return null;
        }
        $findmac = substr($shortmac, 0, 2) . ':' . substr($shortmac, 2, 2) . ':' . substr($shortmac, 4, 2);
        $manufPath = '/opt/pbx3/cache/manuf.txt';
        if (!is_readable($manufPath)) {
            return null;
        }
        $vendorline = `grep -i "^$findmac" "$manufPath" 2>/dev/null`;
        $vendorline = trim($vendorline);
        if ($vendorline === '') {
            return null;
        }
        // manuf.txt can be "OUI\trest" or "OUI rest" (tab or space). Strip OUI and match vendor in the rest.
        $rest = trim(substr($vendorline, strlen($findmac)));
        $supported = 'Snom|Panasonic|Yealink|Polycom|Fanvil|Cisco|Gigaset|Aastra|Grandstream|Vtech';
        if (!preg_match('/(' . $supported . ')/i', $rest, $m)) {
            return null;
        }
        $short_vendor = $m[1];
        if (strcasecmp($short_vendor, 'yealink') === 0) {
            $short_vendor = 'Yealink';
        }
        if (strcasecmp($short_vendor, 'snom') === 0) {
            $short_vendor = 'Snom';
        }
        return $short_vendor;
    }


/**
 * Adjust the provisioning includes depending upon protocol and transport.
 * Only runs when extension has a non-empty provision string.
 *
 * @param  Extension $extension
 * @return void
 */
    private function adjustAstProvSettings(Extension $extension) {
        if ($extension->provision === null || trim((string) $extension->provision) === '') {
            return;
        }
        $provision = $extension->provision;
        $provision = preg_replace('/^\#INCLUDE.*\.tcp.*$/m', '', $provision);
        $provision = preg_replace('/^\#INCLUDE.*\.tls.*$/m', '', $provision);
        $provision = preg_replace('/^\#INCLUDE.*\.udp.*$/m', '', $provision);
        $provision = preg_replace('/^\#INCLUDE.*\.ipv6.*$/m', '', $provision);
        $provision = preg_replace('/^\#INCLUDE.*\.ipv4.*$/m', '', $provision);
        $provision = rtrim($provision);
        $transport = $extension->transport ?? 'udp';
        $protocol = $extension->protocol ?? 'IPV4';
        $shortdevice = substr((string) $extension->device, 0, 4);
        switch ($shortdevice) {
            case 'Snom':
            case 'snom':
                $provision .= $transport === 'tcp' ? "\n#INCLUDE snom.tcp" : ($transport === 'tls' ? "\n#INCLUDE snom.tls" : "\n#INCLUDE snom.udp");
                $provision .= $protocol === 'IPV6' ? "\n#INCLUDE snom.ipv6" : "\n#INCLUDE snom.ipv4";
                break;
            case 'Yeal':
                $provision .= $transport === 'tcp' ? "\n#INCLUDE yealink.tcp" : ($transport === 'tls' ? "\n#INCLUDE yealink.tls" : "\n#INCLUDE yealink.udp");
                $provision .= $protocol === 'IPV6' ? "\n#INCLUDE yealink.ipv6" : "\n#INCLUDE yealink.ipv4";
                break;
            case 'Pana':
                $provision .= $transport === 'tcp' ? "\n#INCLUDE panasonic.tcp" : ($transport === 'tls' ? "\n#INCLUDE panasonic.tls" : "\n#INCLUDE panasonic.udp");
                $provision .= $protocol === 'IPV6' ? "\n#INCLUDE panasonic.ipv6" : "\n#INCLUDE panasonic.ipv4";
                break;
            default:
                break;
        }
        $extension->provision = $provision;
    }

	private function create_default_cos_instances($extension) {

		$costable = Cos::all();

		foreach ($costable as $cos) {

			if ($cos->defaultopen == 'YES') {
				IpPhoneCosOpen::create([
    				'ipphone_pkey' => $extension->pkey,
    				'cos_pkey' => $cos->pkey,
    				'cluster' => $extension->cluster,
    				]);
			}

			if ($cos->defaultclosed == 'YES') {
				IpPhoneCosClosed::create([
    				'ipphone_pkey' => $extension->pkey,
    				'cos_pkey' => $cos->pkey,
    				'cluster' => $extension->cluster,
    				]);
			}		
		}
	}

}
