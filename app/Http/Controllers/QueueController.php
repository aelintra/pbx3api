<?php

namespace App\Http\Controllers;

use App\Queue;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;

class QueueController extends Controller
{
    //

    private $updateableColumns = [

        'conf' => 'string',
        'cluster' => 'exists:cluster,pkey',
        'devicerec' => 'in:None,OTR,OTRR,Inbound',
        'greetnum' => 'regex:/^usergreeting\d{4}$',
        'options' => 'alpha',
    ];

/**
 *
 * @return Ring Groups
 */
    public function index (Queue $queue) {

    	return Queue::orderBy('pkey','asc')->get();
    }

/**
 * Return named queue model instance
 * 
 * @param  Queue
 * @return Queue object
 */
    public function show (Queue $queue) {

    	return response()->json($queue, 200);
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

        $queue = new Queue;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$queue) {

//Check if key exists
            if ($queue->where('pkey','=',$request->pkey)->count()) {
                    $validator->errors()->add('save', "Duplicate Key - " . $request->pkey);
                    return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model 
        move_request_to_model($request,$queue,$this->updateableColumns); 


// create the model         
        try {
            $queue->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return $queue;
    }

/**
 * @param  Request
 * @param  Queue
 * @return json response
 */
    public function update(Request $request, Queue $queue) {

// Validate   
        $validator = Validator::make($request->all(),$this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model   
        move_request_to_model($request,$queue,$this->updateableColumns);


// store the model if it has changed
        try {
            if ($queue->isDirty()) {
                $queue->update();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($queue, 200);
        
    } 


/**
 * Delete  Queue instance
 * @param  Queue
 * @return 204
 */
    public function delete(Queue $queue) {
        $queue->delete();

        return response()->json(null, 204);
    }

}
