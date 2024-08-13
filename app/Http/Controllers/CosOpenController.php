<?php

namespace App\Http\Controllers;

use App\CosOpen;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class CosOpenController extends Controller
{
    //

    private $updateableColumns = [

        'IPphone_pkey' => 'exists:ipphone,pkey',
        'COS_pkey' => 'exists:cos,pkey'

    ];

/**
 *
 * @return CosOpen
 */
    public function index (CosOpen $cosopen) {

    	return CosOpen::orderBy('ipphone_pkey','asc')->get();
    }

/**
 * Return named CosOpen model instance
 * 
 * @param  CosOpen
 * @return CosOpen object
 */
    public function show (CosOpen $cosopen) {

    	return response()->json($cosopen, 200);
    }

/**
 * Create a new CosOpen instance
 * 
 * @param  Request
 * @return New CosOpen
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['IPphone_pkey'] = 'required|exists:ipphone,pkey';
        $this->updateableColumns['COS_pkey'] = 'required|exists:cos,pkey';

        $cosopen = new CosOpen;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$cosopen) {

//Check if key exists
            if ($cosopen->where('IPphone_pkey','=',$request->IPphone_pkey)
                    ->where('COS_pkey','=',$request->COS_pkey)
                    ->count()) {
                $validator->errors()->add('save', "Duplicate Keys, relationship already exists - " . $request->pkey);
                return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$cosopen,$this->updateableColumns); 


// create the model         
        try {
            $cosopen->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $cosopen;
    }

/**
 * @param  Request
 * @param  CosOpen
 * @return json response
 */
    public function update(Request $request, CosOpen $cosopen) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$cosopen,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($cosopen->isDirty()) {
                $cosopen->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($cosopen, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(CosOpen $cosopen) {
        $cosopen->delete();

        return response()->json(null, 204);
    }

}
