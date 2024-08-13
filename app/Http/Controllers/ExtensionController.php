<?php

namespace App\Http\Controllers;

use App\Extension;
use Illuminate\Http\Request;
use Response;

use Validator;
use DB;
use App\CustomClasses\Ami;

class ExtensionController extends Controller
{

	private $updateableColumns = [
    		'active' => 'in:YES,NO',
    		'callbackto' => 'in:desk,cell',
    		'callerid' => 'integer|nullable',
    		'cellphone' => 'integer|nullable',
    		'celltwin' => 'in:ON,OFF',
    		'cluster' => 'exists:cluster,pkey',
    		'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
    		'dvrvmail' => 'exists:ipphone,pkey|nullable',
    		'location' => 'in:local,remote',
    		'protocol' => 'in:IPV4,IPV6',
    		'provision' => 'string|nullable',
    		'provisionwith' => 'in:IP,FQDN',
    		'sndcreds' => 'in:No,Once,Always',
    		'transport' => 'in:udp,tcp,tls,wss',
    		'vmailfwd' => 'email|nullable'
    	];

/**
 * Return Extension Index in pkey order asc
 * 
 * @return Extensions
 */
    public function index () {

    	return Extension::orderBy('pkey','asc')->get();
    }

/**
 * Return named extension instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (Extension $extension) {

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
    public function update(Request $request, Extension $extension) {

// Validate         
    	$validator = Validator::make($request->all(),$this->updateableColumns);

    	if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

// Move post variables to the model    	
    	foreach ($request->post() as $key => $value) {
    		if (array_key_exists($key,$this->updateableColumns)) {
    			$extension->$key = $value;
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

// delete any active Cos instances

        DB::table('IPphoneCOSopen')
        		->where('IPphone_pkey', '=', $extension->pkey)
        		->delete();

        DB::table('IPphoneCOSclosed')
        		->where('IPphone_pkey', '=', $extension->pkey)
        		->delete();

// delete any function key instances

        DB::table('IPphone_Fkey')
        		->where('pkey', '=', $extension->pkey)
        		->delete();	

// delete the extension instance

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

		$vendorline = `grep -i $findmac /opt/sark/www/sark-common/manuf.txt`;

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
//		$this->helper->logit("GETV shortv is $short_vendor  ",5 );
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
		$extension->sipiaxfriend = preg_replace( " /^\#include\s*sark_sip_tls.conf.*$/m ",'',$extension->sipiaxfriend);	
		$extension->sipiaxfriend = preg_replace( " /^\#include\s*sark_sip_tcp.conf.*$/m ",'',$extension->sipiaxfriend);	
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

		$costable = DB::table('cos')->get();

		foreach ($costable as $cos) {

			if ($cos->defaultopen == 'YES') {
				DB::table('IPphoneCOSopen')->insert(
    				[ 'IPphone_pkey' => $extension->pkey, 'COS_pkey' => $cos->pkey ]
    				);
			}

			if ($cos->defaultclosed == 'YES') {
				DB::table('IPphoneCOSclosed')->insert(
    				[ 'IPphone_pkey' => $extension->pkey, 'COS_pkey' => $cos->pkey ]
    				);
			}		
		}
	}

}
