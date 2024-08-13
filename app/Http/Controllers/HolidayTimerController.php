<?php

namespace App\Http\Controllers;

use App\HolidayTimer;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class HolidayTimerController extends Controller
{
    //

    private $updateableColumns = [

        'pkey' => 'string',
        'cluster' => 'exists:cluster,pkey',
        'route' => 'string',
        'routeclass' => 'integer',
        'desc' => 'string',
        'stime' => 'digits:10|nullable',
        'etime' => 'digits:10|nullable'

    ];

/**
 *
 * @return HolidayTimer
 */
    public function index (HolidayTimer $holidaytimer) {

    	return HolidayTimer::orderBy('id','asc')->get();
    }

/**
 * Return named Holidaytimer model instance
 * 
 * @param  HolidayTimer
 * @return HolidayTimer object
 */
    public function show (HolidayTimer $holidaytimer) {

    	return $holidaytimer;
    }

/**
 * Create a new HolidayTimer instance
 * 
 * @param  Request
 * @return New HolidayTimer
 */
    public function save(Request $request) {

// validate 
// 
        $holidaytimer = new HolidayTimer;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$holidaytimer) {

           if (isset($request->route)) {
                $holidaytimer->routeclass = get_route_class($request->route);
            }
            if ($holidaytimer->routeclass == 404) {
                $validator->errors()->add('route', "The routing target could not be resolved " . $request->route);               
            }            

        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$holidaytimer,$this->updateableColumns); 

        $holidaytimer['pkey'] = 'sched' . rand(100000, 999999);

// create the model         
        try {
            $holidaytimer->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $holidaytimer;
    }

/**
 * @param  Request
 * @param  HolidayTimer
 * @return json response
 */
    public function update(Request $request, HolidayTimer $holidaytimer) {


// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$holidaytimer) {

           if (isset($request->route)) {
                $holidaytimer->routeclass = get_route_class($request->route);
            }
            if ($holidaytimer->routeclass == 404) {
                $validator->errors()->add('route', "The routing target could not be resolved " . $request->route);               
            }            

        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$holidaytimer,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($holidaytimer->isDirty()) {
                $holidaytimer->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($holidaytimer, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(HolidayTimer $holidaytimer) {
        $holidaytimer->delete();

        return response()->json(null, 204);
    }

}
