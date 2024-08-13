<?php

namespace App\Http\Controllers;

use App\Agent;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class AgentController extends Controller
{
    //

    private $updateableColumns = [

        'cluster' => 'exists:cluster,pkey',
        'name' => 'alpha_dash',
        'passwd' => 'required|integer|min:1001|max:9999',
        'queue1' => 'exists:queue,pkey|nullable',
        'queue2' => 'exists:queue,pkey|nullable',
        'queue3' => 'exists:queue,pkey|nullable',
        'queue4' => 'exists:queue,pkey|nullable',
        'queue5' => 'exists:queue,pkey|nullable',
        'queue6' => 'exists:queue,pkey|nullable'
    ];

/**
 *
 * @return Ring Groups
 */
    public function index (Agent $agent) {

    	return Agent::orderBy('pkey','asc')->get();
    }

/**
 * Return named queue model instance
 * 
 * @param  Agent
 * @return Agent object
 */
    public function show (Agent $agent) {

    	return response()->json($agent, 200);
    }

/**
 * Create a new queue instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['pkey'] = 'required|integer|min:1000|max:9999';
        $this->updateableColumns['cluster'] = 'required|exists:cluster,' . $request->cluster;
        $this->updateableColumns['name'] = 'required|alpha_dash';
        $this->updateableColumns['passwd'] = 'required|integer|min:1001|max:9999';

        $agent = new Agent;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$agent) {

//Check if key exists
            if ($agent->where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$agent,$this->updateableColumns); 


// create the model         
        try {
            $agent->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $agent;
    }

/**
 * @param  Request
 * @param  Agent
 * @return json response
 */
    public function update(Request $request, Agent $agent) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$agent,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($agent->isDirty()) {
                $agent->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($agent, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(Agent $agent) {
        $agent->delete();

        return response()->json(null, 204);
    }

}
