<?php

namespace App\Http\Controllers;

use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    //

    // route table (full_schema.sql). Exclude id, pkey, shortuid, z_*.
    private $updateableColumns = [
        'active' => 'in:YES,NO',
        'alternate' => 'string|nullable',
        'auth' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'dialplan' => 'string|nullable',
        'path1' => 'exists:trunks,pkey|nullable',
        'path2' => 'exists:trunks,pkey|nullable',
        'path3' => 'exists:trunks,pkey|nullable',
        'path4' => 'exists:trunks,pkey|nullable',
        'route' => 'string|nullable',
        'strategy' => 'in:hunt,balance',
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

//Check if key exists within tenant (cluster)
            if ($route->where('pkey','=',$request->pkey)->where('cluster', $request->cluster)->exists()) {
                    $validator->errors()->add('save', "Duplicate Key - " . $request->pkey . " in this tenant.");
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


// store the model if it has changed — update by id only (tenant-safe)
        try {
            if ($route->isDirty()) {
                $id = $route->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Route id is missing'], 409);
                }
                $dirty = $route->getDirty();
                Route::where('id', $id)->update($dirty);
                $route->syncOriginal();
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
