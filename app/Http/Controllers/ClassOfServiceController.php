<?php

namespace App\Http\Controllers;

use App\ClassOfService;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class ClassOfServiceController extends Controller
{
    //

    private $updateableColumns = [

        'active' => 'in:YES,NO',
        'defaultclosed' => 'in:YES,NO',
        'defaultopen' => 'in:YES,NO',
        'description' => 'string|nullable',
        'dialplan' => 'string|nullable',
        'orideclosed'=> 'in:YES,NO',
        'orideopen' => 'in:YES,NO'
    ];

/**
 *
 * @return ClassOfService
 */
    public function index (ClassOfService $classofservice) {

    	return ClassOfService::orderBy('pkey','asc')->get();
    }

/**
 * Return named ClassOfService model instance
 * 
 * @param  ClassOfService
 * @return ClassOfService object
 */
    public function show (ClassOfService $classofservice) {

    	return response()->json($classofservice, 200);
    }

/**
 * Create a new ClassOfService instance
 * 
 * @param  Request
 * @return New ClassOfService
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['pkey'] = 'required|alpha_dash';
        $this->updateableColumns['dialplan'] = 'required';

        $classofservice = new ClassOfService;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$classofservice) {

//Check if key exists
            if ($classofservice->where('pkey','=',$request->pkey)->count()) {
                $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$classofservice,$this->updateableColumns); 


// create the model         
        try {
            $classofservice->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $classofservice;
    }

/**
 * @param  Request
 * @param  ClassOfService
 * @return json response
 */
    public function update(Request $request, ClassOfService $classofservice) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$classofservice,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($classofservice->isDirty()) {
                $classofservice->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($classofservice, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(ClassOfService $classofservice) {
        $classofservice->delete();

        return response()->json(null, 204);
    }

}
