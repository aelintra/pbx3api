<?php

namespace App\Http\Controllers;

use App\Models\CustomApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CustomAppController extends Controller
{
    //

    private $updateableColumns = [
        'pkey' => 'string|nullable',
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'directdial' => 'integer|nullable',
        'extcode' => 'string|nullable',
        'span' => 'in:Internal,External,Both,Neither',
        'striptags' => 'in:YES,NO',
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

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
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $createRules = array_merge($this->updateableColumns, [
            'pkey' => 'required|string',
            'cluster' => 'required|exists:cluster,pkey',
        ]);

        $customapp = new CustomApp;
        $validator = Validator::make($request->all(), $createRules);

        $validator->after(function ($validator) use ($request, $customapp, $clusterShortuid) {
            if (CustomApp::where('pkey', $request->input('pkey'))->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'Duplicate app name in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $customapp, $createRules);
        $customapp->cluster = $clusterShortuid;
        $customapp->pkey = trim((string) $request->input('pkey', ''));
        $customapp->id = generate_ksuid();
        $customapp->shortuid = generate_shortuid();

        try {
            $customapp->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $customapp;
    }

/**
 * @param  Request
 * @param  CustomApp
 * @return json response
 */
    public function update(Request $request, CustomApp $customapp) {
        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $customapp) {
            $newPkey = $request->has('pkey') ? trim((string) $request->input('pkey', '')) : null;
            if ($newPkey !== null && $newPkey !== $customapp->pkey) {
                $clusterShortuid = $customapp->cluster;
                if (cluster_identifier_to_shortuid($request->input('cluster')) !== null) {
                    $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
                }
                if (CustomApp::where('pkey', $newPkey)->where('cluster', $clusterShortuid)->where('id', '!=', $customapp->id)->exists()) {
                    $validator->errors()->add('pkey', 'Duplicate app name in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $customapp, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $customapp->cluster = $clusterShortuid;
        }
        if ($request->has('pkey')) {
            $customapp->pkey = trim((string) $request->input('pkey', ''));
        }

        try {
            if ($customapp->isDirty()) {
                $id = $customapp->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Custom app id is missing'], 409);
                }
                CustomApp::where('id', $id)->update($customapp->getDirty());
                $customapp->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($customapp->fresh(), 200);
    } 


/**
 * Delete  app instance
 * @param  app
 * @return 204
 */
    public function delete(CustomApp $customapp) {
        $customapp->delete();

        return response()->json(null, 204);
    }

}
