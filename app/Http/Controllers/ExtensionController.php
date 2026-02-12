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
		'devicerec' => 'in:default,None,OTR,OTRR,Inbound.Outbound,Both',
		'dvrvmail' => 'exists:ipphone,pkey|nullable',
		'extalert' => 'string|nullable',
		'macaddr' => 'string|nullable',
		'protocol' => 'in:IPV4,IPV6',
		'pjsipuser' => 'string|nullable',
		'technology' => 'string|nullable',
		'transport' => 'in:udp,tcp,tls,wss',
		'vmailfwd' => 'email|nullable',
	];

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
 * Create a new extension (single endpoint). Protocol: SIP | WebRTC | Mailbox.
 * Sets id (ksuid), dvrvmail = pkey. Tenant schema (sqlite_create_tenant.sql).
 *
 * @param  Request  pkey, cluster, desc (name), protocol (SIP|WebRTC|Mailbox), macaddr (optional)
 * @return New extension
 */
    public function save(Request $request) {
        $validator = Validator::make($request->all(), [
            'pkey' => 'required',
            'cluster' => 'required|exists:cluster,pkey',
            'desc' => 'nullable|string|max:255',
            'protocol' => 'required|in:SIP,WebRTC,Mailbox',
            'macaddr' => 'nullable|regex:/^[0-9a-fA-F]{12}$/',
        ]);

        $validator->after(function ($validator) use ($request) {
            if (Extension::where('pkey', $request->pkey)->where('cluster', $request->cluster)->exists()) {
                $validator->errors()->add('save', 'Duplicate extension - ' . $request->pkey . ' in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $pkey = $request->post('pkey');
        $cluster = $request->post('cluster');
        $desc = $request->post('desc');
        $protocol = $request->post('protocol');
        $macaddr = $request->post('macaddr');

        $id = generate_ksuid();
        $shortuid = generate_shortuid();
        $dvrvmail = $pkey;

        $attrs = [
            'id' => $id,
            'shortuid' => $shortuid,
            'pkey' => $pkey,
            'cluster' => $cluster,
            'dvrvmail' => $dvrvmail,
        ];

        if ($protocol === 'Mailbox') {
            $attrs['desc'] = $desc ?: 'MAILBOX';
            $attrs['device'] = 'MAILBOX';
        } elseif ($protocol === 'SIP') {
            $attrs['desc'] = $desc ?: ('Ext' . $pkey);
            $attrs['device'] = 'General SIP';
            $attrs['transport'] = 'udp';
        } else {
            $attrs['desc'] = $desc ?: ('Ext' . $pkey);
            $attrs['device'] = 'WebRTC';
            $attrs['transport'] = 'wss';
        }

        if ($macaddr !== null && $macaddr !== '') {
            $attrs['macaddr'] = $macaddr;
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

        if ($protocol !== 'Mailbox') {
            $this->create_default_cos_instances($extension);
        }

        return response()->json($extension, 201);
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
 * Return named extension runtime values from the PBX
 * 
 * @param  Extension
 * @return extension object
 */
    public function showruntime (Extension $extension) {

        $amiHandle = get_ami_handle();

        $rets = array();
        $rets['cfim'] = $amiHandle->GetDB('cfim', $extension->pkey);
        $rets['cfbs'] = $amiHandle->GetDB('cfbs', $extension->pkey);
        $rets['ringdelay'] = $amiHandle->GetDB('ringdelay', $extension->pkey);

        $amiHandle->logout();

        return Response::json($rets,200);
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

    	try {
    		$extension = Extension::create([
    			'pkey' => $request->post('pkey'),
    			'desc' => 'MAILBOX',
    			'device' => 'MAILBOX',
    			'cluster' => $request->post('cluster'),
                'location' => 'local'
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

        $location = get_location();

    	try {
    		$extension = Extension::create([
    			'pkey' => $request->post('pkey'),
    			'desc' => 'Ext' .$request->post('pkey'),
    			'device' => 'General SIP',
    			'cluster' => $request->post('cluster'),
                'location' => $location
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

	$location = get_location();

	try {
		$extension = Extension::create([
			'pkey' => $request->post('pkey'),
			'desc' => 'Ext' .$request->post('pkey'),
			'device' => 'WebRTC',
			'transport' => 'wss',
			'cluster' => $request->post('cluster'),
			'location' => $location
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

        $location = get_location();

    	// store it

    	try {
        	$extension = Extension::create([
        		'pkey' => $request->post('pkey'),
        		'provision' => $provision,
        		'device' => $device,
        		'cluster' => $request->post('cluster'),
        		'macaddr' => $request->post('macaddr'),
                'location' => $location
        	]);
        } catch (\Exception $e) {
   			return Response::json(['Error' => $e->getMessage()],409);
    	}

// create default Clsss of service contraints

    	$this->create_default_cos_instances($extension);

		return response()->json($extension, 201);
		
    }  

    /**
     * @param  Request
     * @param  Extension
     * @return response
     */
    public function update(ExtensionRequest $request, Extension $extension) {

// Move request body to the model (use all() so JSON body is read; post() is empty for application/json)
    	foreach ($request->all() as $key => $value) {
    		if (array_key_exists($key, $this->updateableColumns)) {
    			$extension->$key = is_string($value) ? trim($value) : $value;
    		}
    	}

// adjust the provisioning string if the transport or protocol has changed
    	if ( $extension->isDirty('transport') ) {
    		$this->adjustAstProvSettings($extension);
    	}

// store the model if it has changed
    	try {
    		if ($extension->isDirty()) {
    			$extension->save();
    		}

        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($extension, 200);
		
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
            IpPhoneCosOpen::where('IPphone_pkey', $extension->pkey)->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }
        try {
            IpPhoneCosClosed::where('IPphone_pkey', $extension->pkey)->delete();
        } catch (\Throwable $e) {
            // table may not exist
        }

        $extension->delete();

        return response()->json(null, 204);
    }
 


/**
 * search the Wireshark vendor database for the root of a given Mac address
 * 
 * @param  string mac address
 * @return string short vendor name, e.g. Yealink, or NULL if not satisfied
 */
    private function getVendorFromMac($mac) {

		$short_vendor = NULL;
		$shortmac = strtoupper(substr($mac,0,6));

		preg_match(" /^([0-9A-F][0-9A-F])([0-9A-F][0-9A-F])([0-9A-F][0-9A-F])$/ ", $shortmac,$matches);

		$findmac = $matches[1] . ":" . trim($matches[2]) . ':' . trim($matches[3]);

		$vendorline = `grep -i $findmac /opt/pbx3/www/pbx3-common/manuf.txt`;

		$delim="\t";
		$short_vendor_cols = explode($delim,$vendorline,3);
		if ( ! empty($short_vendor_cols[1]) ) {
			$short_vendor = $short_vendor_cols[1];
		}
		if (preg_match('/(Snom|Panasonic|Yealink|Polycom|Cisco|Gigaset|Aastra|Grandstream|Vtech)/i',$short_vendor_cols[2],$matches)) {
				$short_vendor = $matches[1];
		}
		else {
			if (preg_match('/(Snom|Panasonic|Yealink|Polycom|Cisco|Gigaset|Aastra|Grandstream|Vtech)/i',$short_vendor,$matches)) {
				$short_vendor = $matches[1];
			}
			else {
				return 0;
			}
		}
// Not all Yealinks advertise themselvs as Yealink, sometimes it's YEALINK
		if (strcasecmp($short_vendor, 'yealink') == 0) {
			$short_vendor = "Yealink";
		}
		return $short_vendor;

}


/**
 * Adjust the provisioning includes depending upon protocol and transport
 * Delete old sipiaxfriend includes (no longer used)
 * 
 * @param  Extension
 * @return null
 */
	private function adjustAstProvSettings(Extension $extension) {

 	// Remove any old sipiax settings (from V5 or earlier)
		$extension->sipiaxfriend = preg_replace( " /^\#include\s*pbx3_sip_tls.conf.*$/m ",'',$extension->sipiaxfriend);	
		$extension->sipiaxfriend = preg_replace( " /^\#include\s*pbx3_sip_tcp.conf.*$/m ",'',$extension->sipiaxfriend);	
		$extension->sipiaxfriend = rtrim($extension->sipiaxfriend);	

	// remove any existing TCP settings
		if (isset($extension->provision)) {
			$extension->provision = preg_replace( " /^\#INCLUDE.*\.tcp.*$/m ",'',$extension->provision);		
	// remove any existing TLS settungs	
			$extension->provision = preg_replace( " /^\#INCLUDE.*\.tls.*$/m ",'',$extension->provision);
	// remove any existing UDP settings		
			$extension->provision = preg_replace( " /^\#INCLUDE.*\.udp.*$/m ",'',$extension->provision);
		
	// remove any existing IPV6 settings	
			$extension->provision = preg_replace( " /^\#INCLUDE.*\.ipv6.*$/m ",'',$extension->provision);	
	// remove sny existing IPV4 settings	
			$extension->provision = preg_replace( " /^\#INCLUDE.*\.ipv4.*$/m ",'',$extension->provision);	
	// clean up			
			$extension->provision = rtrim ($extension->provision);
		}
		
	// Insert new INCLUDES according to transport and protocol settings					
		$shortdevice = substr($extension->device,0,4);
		switch ($shortdevice) {			
			case 'Snom':
			case 'snom':					
				switch ($extension['transport']) {						
					case 'tcp':
						$extension->provision .= "\n#INCLUDE snom.tcp";
						break;
					case 'tls':
						$extension->provision .= "\n#INCLUDE snom.tls";						
						break;
					default: 
						$extension->provision .= "\n#INCLUDE snom.udp";
				}
				switch ($extension['protocol']) {
					case 'IPV6':
						$extension->provision .= "\n#INCLUDE snom.ipv6";
						break;
					default:
						$extension->provision .= "\n#INCLUDE snom.ipv4";
				}
			break;
					
			case 'Yeal':					
				switch ($extension['transport']) {						
					case 'tcp':
						$extension->provision .= "\n#INCLUDE yealink.tcp";
						break;
					case 'tls':
						$extension->provision .= "\n#INCLUDE yealink.tls";
						break;
					default: 
						$extension->provision .= "\n#INCLUDE yealink.udp";
				}
				switch ($extension['protocol']) {
					case 'IPV6':
						$extension->provision .= "\n#INCLUDE yealink.ipv6";
						break;
					default:
						$extension->provision .= "\n#INCLUDE yealink.ipv4";
				}
			break;				
			
			case 'Pana':					
				switch ($extension['transport']) {						
					case 'tcp':
						$extension->provision .= "\n#INCLUDE panasonic.tcp";
						break;
					case 'tls':
						$extension->provision .= "\n#INCLUDE panasonic.tls";
						break;	
					default: 
						$extension->provision .= "\n#INCLUDE panasonic.udp";
				}
				switch ($extension['protocol']) {
					case 'IPV6':
						$extension->provision .= "\n#INCLUDE panasonic.ipv6";
						break;
					default:
						$extension->provision .= "\n#INCLUDE panasonic.ipv4";
				}
			break;						
		}

	}

	private function create_default_cos_instances($extension) {

		$costable = Cos::all();

		foreach ($costable as $cos) {

			if ($cos->defaultopen == 'YES') {
				IpPhoneCosOpen::create([
    				'IPphone_pkey' => $extension->pkey,
    				'COS_pkey' => $cos->pkey,
    				'cluster' => $extension->cluster,
    				]);
			}

			if ($cos->defaultclosed == 'YES') {
				IpPhoneCosClosed::create([
    				'IPphone_pkey' => $extension->pkey,
    				'COS_pkey' => $cos->pkey,
    				'cluster' => $extension->cluster,
    				]);
			}		
		}
	}

}
