<?php

namespace App\Http\Controllers;

use App\Trunk;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class TrunkController extends Controller
{
    //
    	private $updateableColumns = [
    		'active' => 'in:YES,NO', 
			'alertinfo' => 'string',
			'callerid' => 'integer',
			'callprogress' => 'in:ON,OFF',
			'cluster' => 'exists:cluster,pkey',
			'description' => 'alpha_num',
			'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
			'disa' => 'in:DISA,CALLBACK|nullable',
			'disapass' => 'alpha_num|nullable',
			'host' => 'string', 
			'inprefix' => 'integer|nullable',
			'match' => 'integer|nullable',
			'moh' => 'in:ON,OFF',
			'password' => 'alpha_num|nullable',
			'peername' => 'string',
			'register' => 'string|nullable',
			'sipiaxpeer' => 'string',
			'sipiaxuser' => 'string',
			'swoclip' => 'in:YES,NO',
			'tag' => 'alpha_num|nullable',
			'transform' => [
				'regex:/$(\d+?:\d+?\s*)+',
				'nullable'
			],
			'trunkname' => 'alpha_num',
			'username' => 'alpha_num|nullable',
			'z_updater' => 'alpha_num'
    	];

/**
 * Return Trunk Index in pkey order asc
 * Historically, the same relation is used to hold noth trun entries and DDI/CLID entries
 * ...so we must filter the relation.  
 * This will likley be addressed in V7 with a new relation purely for DDI
 * 
 * @return Trunks
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
 * 
 * @param  Request
 * @return New Trunk
 */
    public function save(Request $request) {

// validation 
  		$this->updateableColumns['pkey'] = 'required';
  		$this->updateableColumns['carrier'] = 'required|in:GeneralSIP,GeneralIAX2';
		$this->updateableColumns['cluster'] = 'required|exists:cluster,' . $request->cluster;
		$this->updateableColumns['username'] = 'required';
		$this->updateableColumns['host'] = 'required';

    	$validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (Trunk::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });  

        if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

    	$trunk = new Trunk;  	

    	move_request_to_model($request,$trunk,$this->updateableColumns); 

//  peername = username unless overriden by caller
    	if (empty($trunk->peername)) {
    		$trunk->peername = $trunk->username;
    	}

// trunkname = peername unless overridden by caller
    	if (empty($trunk->trunkname)) {
    		$trunk->trunkname = $trunk->peername;
    	}

// Set technology
    	$trunk->technology = 'SIP';
		if ($trunk->carrier == 'GeneralIAX2') {
			$trunk->technology = 'IAX2';
			$trunk->technology = $trunk->peername;
		}

// Copy in the Asterisk stanzas    
		$this->copy_asterisk_stanzas_from_carrier ($request, $trunk);

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

// Validate   

    	$validator = Validator::make($request->all(),$this->updateableColumns);

    	$validator->after(function ($validator) use ($request) {
			// check the host
			if ($request->host){
    			if ( ! valid_ip_or_domain ($request->host) ) {
        			$validator->errors()->add('host', "Host must be valid IP or valid domain name " . $request->host);
    			}
    		}
		});

		if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

// Move post variables to the model   

		move_request_to_model($request,$trunk,$this->updateableColumns);


// store the model if it has changed
    	try {
    		if ($trunk->isDirty()) {
    			$trunk->save();
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

 
/**
 * Copies and sets Asterisk stanza template from Carrier into LineIO
 * 
 * @param  REQUEST
 * @param  MODEL
 * @return NULL
 */
	private function copy_asterisk_stanzas_from_carrier ($request, $trunk) {
 
// Get the templates from the carrier row

        $template = DB::table('carrier')->where('pkey', $trunk->carrier)->first();

        if (isset( $template->sipiaxpeer )) {

      		$template->sipiaxpeer = preg_replace ('/username=/',"username=" . $trunk->username, $template->sipiaxpeer);
      		$template->sipiaxpeer = preg_replace ('/fromuser=/',"fromuser=" . $trunk->username, $template->sipiaxpeer);
      		$template->sipiaxpeer = preg_replace ('/secret=/',"secret=" . $trunk->password, $template->sipiaxpeer);
      		$template->sipiaxpeer = preg_replace ('/host=/',"host=" . $trunk->host, $template->sipiaxpeer);
      		$template->sipiaxpeer = preg_replace ('/^\s+/',"", $template->sipiaxpeer);
      		$template->sipiaxpeer = preg_replace ('/\s+$/',"", $template->sipiaxpeer);

            if ( $trunk->carrier == "InterSARK") {
				$template->sipiaxpeer = preg_replace ('/mainmenu/',"priv_sibling", $template->sipiaxpeer);
				$template->sipiaxpeer = preg_replace ('/trunk=yes/',"trunk=no", $template->sipiaxpeer);
            }  

            if ( !preg_match(' /allow=/ ',$template->sipiaxpeer)) {				
        		$template->sipiaxpeer .= "\ndisallow=all\nallow=alaw\nallow=ulaw";
        	}       	
        }

// sipiaxuser only gets set for IAX2 trynks
        if (isset( $template->sipiaxuser )) {
        	
      		$template->sipiaxuser = preg_replace ('/username=/',"username=" . $trunk->username, $template->sipiaxuser);
      		$template->sipiaxuser = preg_replace ('/fromuser=/',"fromuser=" . $trunk->username, $template->sipiaxuser);
      		$template->sipiaxuser = preg_replace ('/secret=/',"secret=" . $trunk->password, $template->sipiaxuser);
        	$template->sipiaxuser = preg_replace ('/^\s+/',"", $template->sipiaxuser);
      		$template->sipiaxuser = preg_replace ('/\s+$/',"", $template->sipiaxuser);
			
// Possibly handle trunk privilege here - it used to be in V3/4 but it was never used so I think it's better left alone.
          
        }
        $trunk->sipiaxpeer = $template->sipiaxpeer;
		$trunk->sipiaxuser = $template->sipiaxuser;

	}

}
