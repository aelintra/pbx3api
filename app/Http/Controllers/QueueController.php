<?php

namespace App\Http\Controllers;

use App\Models\Queue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    //

    // queue table (sqlite_create_tenant.sql). Exclude id, shortuid, z_*, name (deprecated). pkey = queue dial, 3-5 digits, unique per tenant.
    private $updateableColumns = [
        'pkey' => 'nullable|string|regex:/^\d{3,5}$/',
        'active' => 'in:YES,NO',
        'alertinfo' => 'string|nullable',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'devicerec' => 'in:None,OTR,OTRR,Inbound,default',
        'divert' => 'integer|nullable',
        'greetnum' => 'string|nullable',
        'greeting' => 'string|nullable',
        'members' => 'string|nullable',
        'musicclass' => 'string|nullable',
        'options' => 'string|nullable',
        'retry' => 'integer|nullable',
        'wrapuptime' => 'integer|nullable',
        'maxlen' => 'integer|nullable',
        'outcome' => 'string|nullable',
        'strategy' => 'in:ringall,roundrobin,leastrecent,fewestcalls,random,rrmemory',
        'timeout' => 'integer|nullable',
    ];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

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

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

// validate — pkey = queue number: 3-5 digits, unique per tenant
        $this->updateableColumns['pkey'] = 'required|string|regex:/^\d{3,5}$/';
        $this->updateableColumns['cluster'] = 'required|exists:cluster,pkey';

        $queue = new Queue;

        $validator = Validator::make($request->all(), $this->updateableColumns, [
            'pkey.regex' => 'Queue number must be 3-5 digits.',
        ]);

        $validator->after(function ($validator) use ($request, $queue, $clusterShortuid) {

// Check unique per tenant (cluster); DB stores shortuid
            if ($queue->where('pkey', '=', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That queue number is already in use in this tenant.');
                return;
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }
    
// Move post variables to the model
        move_request_to_model($request, $queue, $this->updateableColumns);
        $queue->cluster = $clusterShortuid;

        $queue->id = generate_ksuid();
        $queue->shortuid = generate_shortuid();

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

// Validate — pkey when present must be 3-5 digits (updateableColumns); uniqueness in after()
        $validator = Validator::make($request->all(), $this->updateableColumns, [
            'pkey.regex' => 'Queue number must be 3-5 digits.',
        ]);

        $validator->after(function ($validator) use ($request, $queue) {
            $pkeySubmitted = $request->input('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $queue->getAttribute('pkey')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster')) ?? $queue->cluster;
                if ($clusterShortuid !== null && Queue::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $queue->id)->exists()) {
                    $validator->errors()->add('pkey', 'That queue number is already in use in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Move post variables to the model
        move_request_to_model($request, $queue, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $queue->cluster = $clusterShortuid;
        }

// store the model if it has changed — update by id only (tenant-safe)
        try {
            if ($queue->isDirty()) {
                $id = $queue->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Queue id is missing'], 409);
                }
                $dirty = $queue->getDirty();
                Queue::where('id', $id)->update($dirty);
                $queue->syncOriginal();
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
