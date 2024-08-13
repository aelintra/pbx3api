<?php

namespace App\Http\Controllers;

use App\DayTimer;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class DayTimerController extends Controller
{
    //

    private $updateableColumns = [

//        'pkey' => null,
        'cluster' => 'exists:cluster,pkey',
        'datemonth' => 'in:*,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31',
        'dayofweek' => 'in:*,mon,tue,wed,thu,fri,sat,sun',
        'desc' => 'string',
        'month' => 'in:*,jan,feb,mar,apr,may,jun,jul,aug,sep,oct,nov,dec',
//        'state' => 'IDLE',
        'timespan' => [
                        'regex:/^\*|(2[0-3]|[01][0-9]):([0-5][0-9])\-(2[0-3]|[01][0-9]):([0-5][0-9])$/'
        ]

    ];

/**
 *
 * @return CosOpen
 */
    public function index (DayTimer $daytimer) {

    	return DayTimer::orderBy('id','asc')->get();
    }

/**
 * Return named CosOpen model instance
 * 
 * @param  DayTimer
 * @return DayTimer object
 */
    public function show (DayTimer $daytimer) {

    	return $daytimer;
    }

/**
 * Create a new CosOpen instance
 * 
 * @param  Request
 * @return New DayTimer
 */
    public function save(Request $request) {

// validate 
// 
        $daytimer = new DayTimer;

        $validator = Validator::make($request->all(),$this->updateableColumns);


        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$daytimer,$this->updateableColumns); 

        $daytimer['pkey'] = 'dateSeg' . rand(100000, 999999);

// create the model         
        try {
            $daytimer->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $daytimer;
    }

/**
 * @param  Request
 * @param  CosOpen
 * @return json response
 */
    public function update(Request $request, DayTimer $daytimer) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$daytimer,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($daytimer->isDirty()) {
                $daytimer->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($daytimer, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(DayTimer $daytimer) {
        $daytimer->delete();

        return response()->json(null, 204);
    }

}
