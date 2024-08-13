<?php

namespace App\Http\Controllers;

use App\Route;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class RouteController extends Controller
{
    //

    private $updateableColumns = [

        'active' => 'in:YES,NO',
        'auth' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'desc' => 'alpha_dash',
        'dialplan' => 'string',
        'path1' => 'exists:lineio,pkey|nullable',
        'path2' => 'exists:lineio,pkey|nullable',
        'path3' => 'exists:lineio,pkey|nullable',
        'path4' => 'exists:lineio,pkey|nullable',
        'strategy' => 'in:hunt,balance'
    ];

/**
 *
 * @return Ring Groups
 */
    public function index (Route $route) {

    	return Route::orderBy('pkey','asc')->get();
    }

/**
 * Return named queue model instance
 * 
 * @param  Route
 * @return Route object
 */
    public function show (Route $route) {

    	return response()->json($route, 200);
    }

/**
 * Create a new queue instance
 * 
 * @param  Request
 * @return New Did
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['pkey'] = 'required';
        $this->updateableColumns['cluster'] = 'required|exists:cluster,' . $request->cluster;

        $route = new Route;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$route) {

//Check if key exists
            if ($route->where('pkey','=',$request->pkey)->count()) {
                    $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                    return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$route,$this->updateableColumns); 


// create the model         
        try {
            $route->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $route;
    }

/**
 * @param  Request
 * @param  Route
 * @return json response
 */
    public function update(Request $request, Route $route) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$route,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($route->isDirty()) {
                $route->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($route, 200);
        
    } 


/**
 * Delete  Route instance
 * @param  Route
 * @return 204
 */
    public function delete(Route $route) {
        $route->delete();

        return response()->json(null, 204);
    }

}
