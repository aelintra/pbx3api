<?php

namespace App\Http\Controllers;

use App\InboundRoute;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class InboundRouteController extends Controller
{
    //
    	private $updateableColumns = [
    		'active' => 'in:YES,NO', 
			'alertinfo' => 'string',
            'carrier' => 'in:DiD,CLID',
            'closeroute' => 'string',
			'cluster' => 'exists:cluster,pkey',
			'description' => 'alpha_num',
			'disa' => 'in:DISA,CALLBACK|nullable',
			'disapass' => 'alpha_num|nullable',
			'inprefix' => 'integer|nullable',
			'moh' => 'in:ON,OFF',
            'openroute' => 'string',
			'swoclip' => 'in:YES,NO',
			'tag' => 'alpha_num|nullable',
			'trunkname' => 'alpha_num',
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

    	return InboundRoute::where('technology', '=', 'DiD')
    		->orWhere ('technology', '=', 'CLID' ) 		
    		->orderBy('pkey','asc')->get();
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (InboundRoute $inboundroute) {

    	return response()->json($inboundroute, 200);
    }

/**
 * Create a new Did instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['pkey'] = 'required';
        $this->updateableColumns['carrier'] = 'required|in:DiD,CLID';
        $this->updateableColumns['cluster'] = 'required|exists:cluster,' . $request->cluster;
        $this->updateableColumns['trunkname'] = 'required';

        $inboundroute = new InboundRoute;
        $inboundroute->openroute = 'None';
        $inboundroute->closeroute = 'None';

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$inboundroute) {

//Check if key exists
            if ($inboundroute->where('pkey','=',$request->pkey)->count()) {
                    $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                    return;
            } 
// check routes and get routeclass
            $this->check_inbound_routes($request, $inboundroute, $validator);                      
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$inboundroute,$this->updateableColumns); 


// Set technology
        $inboundroute->technology = $inboundroute->carrier;

// create the model         
        try {
            $inboundroute->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $inboundroute;
    }



/**
 * @param  Request
 * @param  InboundRoute
 * @return json response
 */
    public function update(Request $request, InboundRoute $inboundroute) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

// Check if route targets have changed and set the routeclass if they have
        $validator->after(function ($validator) use ($request,$inboundroute) {

            $this->check_inbound_routes($request, $inboundroute, $validator);

        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$inboundroute,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($inboundroute->isDirty()) {
                $inboundroute->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($inboundroute, 200);
        
    } 


/**
 * Delete  InboundRoute instance
 * @param  InboundRoute
 * @return 204
 */
    public function delete(InboundRoute $inboundroute) {
        $inboundroute->delete();

        return response()->json(null, 204);
    }


/**
 * @param  $request
 * @param  $inboundroute
 * @return NULL
 */
    private function check_inbound_routes($request, $inboundroute, $validator) {

            if (isset($request->openroute)) {
                $inboundroute->routeclassopen = get_route_class($request->openroute);
            }

            if ($inboundroute->routeclassopen == 404) {
                $validator->errors()->add('openroute', "The target could not be resolved " . $request->openroute);               
            }

            if (isset($request->closeroute)) {  
                $inboundroute->routeclassclosed = get_route_class($request->closeroute);
            }

            if ($inboundroute->routeclassclosed == 404) {
                $validator->errors()->add('closeroute', "The target could not be resolved " . $request->closeroute);             
            }                      

    }
}
