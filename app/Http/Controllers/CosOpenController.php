<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\CosOpen;
use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class CosOpenController extends Controller
{
    use EnforcesClusterScope;

    private $updateableColumns = [

        'ipphone_pkey' => 'exists:ipphone,pkey',
        'cos_pkey' => 'exists:cos,pkey'

    ];

/**
 *
 * @return CosOpen
 */
    public function index (CosOpen $cosopen) {

    	return $this->applyClusterScope(CosOpen::query(), 'cluster')->orderBy('ipphone_pkey','asc')->get();
    }

/**
 * Return named CosOpen model instance
 * 
 * @param  CosOpen
 * @return CosOpen object
 */
    public function show (CosOpen $cosopen) {

    	$this->assertModelClusterAllowed($cosopen);
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
        $this->updateableColumns['ipphone_pkey'] = 'required|exists:ipphone,pkey';
        $this->updateableColumns['cos_pkey'] = 'required|exists:cos,pkey';

        $cosopen = new CosOpen;

        $validator = Validator::make($request->all(),$this->updateableColumns);

        $validator->after(function ($validator) use ($request,$cosopen) {

//Check if key exists
            if ($cosopen->where('ipphone_pkey','=',$request->ipphone_pkey)
                    ->where('cos_pkey','=',$request->cos_pkey)
                    ->count()) {
                $validator->errors()->add('save', "Duplicate Keys, relationship already exists - " . $request->pkey);
                return;
            }                 
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(),422);
        }

// Resolve owning extension's cluster (tenant IDOR guard); ipphone_pkey is not globally unique
// so we take the first match — consistent with the model's own composite-key limitations.
        $extension = Extension::where('pkey', $request->input('ipphone_pkey'))->first();
        if ($extension === null) {
            return response()->json(['ipphone_pkey' => ['Extension not found.']], 422);
        }
        $clusterShortuid = cluster_identifier_to_shortuid($extension->cluster) ?? (string) $extension->cluster;
        $this->assertClusterAllowed($clusterShortuid);

// Move post variables to the model 
        move_request_to_model($request,$cosopen,$this->updateableColumns); 
        $cosopen->cluster = $clusterShortuid;


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

        $this->assertModelClusterAllowed($cosopen);

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
                $cosopen->save();
            }

        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()],409);
        }

        return response()->json($cosopen, 200);
        
    } 


/**
 * Delete  CoS instance
 * @param  CoS
 * @return 204
 */
    public function delete(CosOpen $cosopen) {
        $this->assertModelClusterAllowed($cosopen);
        $cosopen->delete();

        return response()->json(null, 204);
    }

}
