<?php

namespace App\Http\Controllers;

use App\CustomApp;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class CustomAppController extends Controller
{
    //

    private $updateableColumns = [

        'cluster' => 'exists:cluster,pkey',
        'desc' => 'string|nullable',
        'extcode' => 'string|nullable',
        'span' => 'in:Internal,External,Both,Neither',
        'striptags' => 'in:YES,NO'
    ];

/**
 *
 * @return CustomApp
 */
    public function index (CustomApp $customapp) {

    	return CustomApp::orderBy('pkey','asc')->get();
    }

/**
 * Return named queue model instance
 * 
 * @param  CustomApp
 * @return CustomApp object
 */
    public function show (CustomApp $customapp) {

    	return response()->json($customapp, 200);
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

        $customapp = new CustomApp;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$customapp) {

//Check if key exists
            if ($customapp->where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$customapp,$this->updateableColumns); 


// create the model         
        try {
            $customapp->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $customapp;
    }

/**
 * @param  Request
 * @param  CustomApp
 * @return json response
 */
    public function update(Request $request, CustomApp $customapp) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$customapp,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($customapp->isDirty()) {
                $customapp->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($customapp, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(CustomApp $customapp) {
        $customapp->delete();

        return response()->json(null, 204);
    }

}
