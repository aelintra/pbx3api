<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class InboundRouteController extends Controller
{
    //
    	// Only columns that exist in inroutes table (full_schema.sql). 'carrier' is request-only → stored as technology.
    	private $updateableColumns = [
    		'active' => 'in:YES,NO',
			'alertinfo' => 'string',
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
 * Return InboundRoute index in pkey order asc.
 * Instance schema uses inroutes table (DDI/CLID); trunks are in trunks table.
 *
 * @return \Illuminate\Support\Collection
 */
    public function index () {

    	return InboundRoute::orderBy('pkey','asc')->get();
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

// validate (carrier is request-only, stored as technology in DB)
        $rules = array_merge($this->updateableColumns, [
            'pkey' => 'required',
            'carrier' => 'required|in:DiD,CLID',
            'cluster' => 'required|exists:cluster,pkey',
            'trunkname' => 'nullable|alpha_num',
        ]);

        $inboundroute = new InboundRoute;
        $inboundroute->openroute = 'None';
        $inboundroute->closeroute = 'None';

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $inboundroute) {
            if ($inboundroute->where('pkey', '=', $request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);

        if (empty($inboundroute->trunkname)) {
            $inboundroute->trunkname = $inboundroute->pkey;
        }

        $inboundroute->id = generate_ksuid();
        $inboundroute->shortuid = generate_shortuid();
        $inboundroute->technology = $request->input('carrier', 'DiD');

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

        $validator = Validator::make($request->all(), $this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

        move_request_to_model($request, $inboundroute, $this->updateableColumns);
        if ($request->has('carrier')) {
            $inboundroute->technology = $request->input('carrier');
        }

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
}
