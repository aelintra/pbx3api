<?php

namespace App\Http\Controllers;

use App\Ivr;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class IvrController extends Controller
{
    //
    	private $updateableColumns = [

            'pkey' => 'string|nullable',
            'alert0' => 'string|nullable',
            'alert1' => 'string|nullable',
            'alert2' => 'string|nullable',
            'alert3' => 'string|nullable',
            'alert4' => 'string|nullable',
            'alert5' => 'string|nullable',
            'alert6' => 'string|nullable',
            'alert7' => 'string|nullable',
            'alert8' => 'string|nullable',
            'alert9' => 'string|nullable',
            'alert10' => 'string|nullable',
            'alert11' => 'string|nullable',            
            'description' => 'string|nullable',
            'cluster' => 'exists:cluster,pkey',
            'greetnum' => 'integer',
            'listenforext' => 'in:YES,NO',
            'option0' => 'string|nullable',
            'option1' => 'string|nullable',
            'option2' => 'string|nullable',
            'option3' => 'string|nullable',
            'option4' => 'string|nullable',
            'option5' => 'string|nullable',
            'option6' => 'string|nullable',
            'option7' => 'string|nullable',
            'option8' => 'string|nullable',
            'option9' => 'string|nullable',
            'option10' => 'string|nullable',
            'option11' => 'string|nullable',
            'tag0' => 'string|nullable',
            'tag1' => 'string|nullable',
            'tag2' => 'string|nullable',
            'tag3' => 'string|nullable',
            'tag4' => 'string|nullable',
            'tag5' => 'string|nullable',
            'tag6' => 'string|nullable',
            'tag7' => 'string|nullable',
            'tag8' => 'string|nullable',
            'tag9' => 'string|nullable',
            'tag10' => 'string|nullable',
            'tag11' => 'string|nullable',            
            'timeout' => 'operator',                    
            'timeoutrouteclass' => '100',
            'z_updater' => 'alpha_num'

    	];

/**

 * 
 * @return Ivrs
 */
    public function index () {

    	return Ivr::orderBy('pkey','asc')->get();
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (Ivr $ivr) {

    	return $ivr;
    }

/**
 * Create a new Ivr instance
 * 
 * @param  Request
 * @return New Ivr
 */
    public function save(Request $request) {

// validation 
  		$this->updateableColumns['pkey'] = 'required';
		$this->updateableColumns['cluster'] = 'required|exists:cluster,' . $request->cluster;

    	$validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request) {
//Check if key exists
            if (IVR::where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                              

            if($this->check_options($request, $ivr, $validator) == 404) {
                return;
            }
            if($this->check_timeout($request, $ivr, $validator) == 404) {
                return;
            }

        });

        if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

    	$ivr = new Ivr;  	

    	move_request_to_model($request,$ivr,$this->updateableColumns); 
        $this->check_options($request, $ivr);

// create the model			
    	try {

    		$ivr->save();

    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

    	return $ivr;
	}



/**
 * @param  Request
 * @param  Ivr
 * @return json response
 */
    public function update(Request $request, Ivr $ivr) {

// Validate   

    	$validator = Validator::make($request->all(),$this->updateableColumns);

    	$validator->after(function ($validator) use ($request) {
	
		});

		if ($validator->fails()) {
    		return response()->json($validator->errors(),422);
    	}

// Move post variables to the model   

		move_request_to_model($request,$ivr,$this->updateableColumns);
        $this->check_options($request, $ivr);

// store the model if it has changed
    	try {
    		if ($ivr->isDirty()) {
    			$ivr->save();
    		}
        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($ivr, 200);
		
    } 


/**
 * Delete  Extension instance
 * @param  Extension
 * @return NULL
 */
    public function delete(Ivr $ivr) {
        $ivr->delete();

        return response()->json(null, 204);
    }

/**
 * @param  $request
 * @param  $ringgroup
 * @return NULL
 */
    private function check_options($request, $ivr) {

            for ($i = 0; $i <= 12; $i++) {

/*
    Iterate over the key options (0->12) using PHP variable variables to poke the object ()
 */
                if (isset($request->{'option' . $i} )) {
                    $ivr->{'routeclass' . $i} = get_route_class($request->{'option' . $i} );
                }

                if ($ivr->{'routeclass' . $i} == 404) {
                    return Response::json(['Error' =>'outcome', "The target could not be resolved " . $request->{'option' . $i} ]);               
                } 
            }                       

    }

 


}
