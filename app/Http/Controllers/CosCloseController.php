<?php

namespace App\Http\Controllers;

use App\CosClose;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class CosCloseController extends Controller
{
    //

    private $updateableColumns = [

        'IPphone_pkey' => 'exists:ipphone,pkey',
        'COS_pkey' => 'exists:cos,pkey'

    ];

/**
 *
 * @return CosClose
 */
    public function index (CosClose $cosclose) {

    	return CosClose::orderBy('cos_pkey','asc')->get();
    }

/**
 * Return named CosOpen model instance
 * 
 * @param  CosClose
 * @return CosClose object
 */
    public function show (CosClose $cosclose) {

    	return response()->json($cosclose, 200);
    }

/**
 * Create a new CosClose instance
 * 
 * @param  Request
 * @return New CosClose
 */
    public function save(Request $request) {

// validate 
        $this->updateableColumns['IPphone_pkey'] = 'required|exists:ipphone,pkey';
        $this->updateableColumns['COS_pkey'] = 'required|exists:cos,pkey';

        $cosclose = new CosClose;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$cosclose) {

//Check if key exists
            if ($cosclose->where('IPphone_pkey','=',$request->IPphone_pkey)
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
        move_request_to_model($request,$cosclose,$this->updateableColumns); 


// create the model         
        try {
            $cosclose->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $cosclose;
    }

/**
 * @param  Request
 * @param  CosOpen
 * @return json response
 */
    public function update(Request $request, CosClose $cosclose) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$cosclose,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($cosclose->isDirty()) {
                $cosclose->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($cosclose, 200);
        
    } 


/**
 * Delete  Agent instance
 * @param  Agent
 * @return 204
 */
    public function delete(CosOpen $cosclose) {
        $cosclose->delete();

        return response()->json(null, 204);
    }

}
