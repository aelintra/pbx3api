<?php

namespace App\Http\Controllers;

use App\Models\Ivr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class IvrController extends Controller
{
    //
	// ivrmenu table (sqlite_create_tenant.sql). pkey = IVR number 3-5 digits, unique per cluster. Exclude id, shortuid, z_*, name (deprecated).
    	private $updateableColumns = [
            'pkey' => 'nullable|string|regex:/^\d{3,5}$/',
            'active' => 'in:YES,NO',
            'alert0' => 'string|nullable',
            'alert1' => 'string|nullable',
            'alert2' => 'string|nullable',
            'alert3' => 'string|nullable',
            'alert4' => 'string|nullable',
            'alert5' => 'string|nullable',
            'alert6' => 'string|nullable',
            'alert7' => 'string|nullable',
            'alert8' => 'string|nullable',
            'alert9' => 'string|nullable',
            'alert10' => 'string|nullable',
            'alert11' => 'string|nullable',
            'cluster' => 'exists:cluster,pkey',
            'cname' => 'string|nullable',
            'description' => 'string|nullable',
            'greetnum' => 'string|nullable',
            'listenforext' => 'in:YES,NO',
            'option0' => 'string|nullable',
            'option1' => 'string|nullable',
            'option2' => 'string|nullable',
            'option3' => 'string|nullable',
            'option4' => 'string|nullable',
            'option5' => 'string|nullable',
            'option6' => 'string|nullable',
            'option7' => 'string|nullable',
            'option8' => 'string|nullable',
            'option9' => 'string|nullable',
            'option10' => 'string|nullable',
            'option11' => 'string|nullable',
            'tag0' => 'string|nullable',
            'tag1' => 'string|nullable',
            'tag2' => 'string|nullable',
            'tag3' => 'string|nullable',
            'tag4' => 'string|nullable',
            'tag5' => 'string|nullable',
            'tag6' => 'string|nullable',
            'tag7' => 'string|nullable',
            'tag8' => 'string|nullable',
            'tag9' => 'string|nullable',
            'tag10' => 'string|nullable',
            'tag11' => 'string|nullable',
            'timeout' => 'string|nullable',
    	];

	/** Return column names that are updateable (for schema metadata). */
	public function getUpdateableColumns(): array
	{
		return array_keys($this->updateableColumns);
	}

/**

 * 
 * @return Ivrs
 */
    public function index ()
    {
        return Ivr::orderBy('pkey', 'asc')->get();
    }

/**
 * Return named extension model instance
 * 
 * @param  Extension
 * @return extension object
 */
    public function show (Ivr $ivr)
    {
        return $ivr;
    }

/**
 * Create a new Ivr instance
 * 
 * @param  Request
 * @return New Ivr
 */
    public function save(Request $request) {

        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required|string|regex:/^\d{3,5}$/',
            'cluster' => 'required|exists:cluster,pkey',
        ]);

        $validator = Validator::make($request->all(), $createRules, [
            'pkey.regex' => 'IVR number must be 3-5 digits.',
        ]);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            if (Ivr::where('pkey', '=', $request->pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That IVR number is already in use in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $ivr = new Ivr;

        move_request_to_model($request, $ivr, $this->updateableColumns);
        $ivr->cluster = $clusterShortuid; 
        $this->check_options($request, $ivr);

        // Populate id (KSUID) and shortuid per ivrmenu schema (same pattern as Trunk/InboundRoute)
        $ivr->id = generate_ksuid();
        $ivr->shortuid = generate_shortuid();

// create the model			
    	try {

    		$ivr->save();

    	} catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

    	return $ivr;
	}



/**
 * @param  Request
 * @param  Ivr
 * @return json response
 */
    public function update(Request $request, Ivr $ivr) {

        $validator = Validator::make($request->all(), $this->updateableColumns, [
            'pkey.regex' => 'IVR number must be 3-5 digits.',
        ]);

        $validator->after(function ($validator) use ($request, $ivr) {
            $pkeySubmitted = $request->input('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $ivr->getAttribute('pkey')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster')) ?? $ivr->cluster;
                if ($clusterShortuid !== null && Ivr::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $ivr->id)->exists()) {
                    $validator->errors()->add('pkey', 'That IVR number is already in use in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $ivr, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $ivr->cluster = $clusterShortuid;
        }
        $this->check_options($request, $ivr);

// store the model if it has changed — update by id only (tenant-safe)
    	try {
    		if ($ivr->isDirty()) {
    			$id = $ivr->id;
    			if ($id === null || $id === '') {
    				return Response::json(['Error' => 'Ivr id is missing'], 409);
    			}
    			$dirty = $ivr->getDirty();
    			Ivr::where('id', $id)->update($dirty);
    			$ivr->syncOriginal();
    		}
        } catch (\Exception $e) {
    		return Response::json(['Error' => $e->getMessage()],409);
    	}

		return response()->json($ivr, 200);
		
    } 


/**
 * Delete  Extension instance
 * @param  Extension
 * @return NULL
 */
    public function delete(Ivr $ivr) {
        $ivr->delete();

        return response()->json(null, 204);
    }

/**
 * @param  $request
 * @param  $ringgroup
 * @return NULL
 */
    private function check_options($request, $ivr) {
        // routeclass no longer exists in schema; option0-11 / timeout are destination names only
    }

 


}
