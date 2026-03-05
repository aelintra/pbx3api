<?php

namespace App\Http\Controllers;

use App\Models\Conference;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class ConferenceController extends Controller
{
    // meetme table (sqlite_create_tenant.sql). pkey = room number (integer), unique per cluster.
    private $updateableColumns = [
        'pkey' => 'nullable|integer',
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'adminpin' => 'string|nullable',
        'description' => 'string|nullable',
        'pin' => 'string|nullable',
        'type' => 'in:simple,hosted',
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(Conference $conference)
    {
        return Conference::orderBy('pkey', 'asc')->get();
    }

    /** Export conferences list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $conferences = Conference::orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($conferences);
        return Pdf::loadView('exports.conferences-pdf', ['conferences' => $conferences])
            ->setPaper('a4', 'landscape')
            ->download('conferences.pdf');
    }

    public function show(Conference $conference)
    {
        return response()->json($conference, 200);
    }

    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->cluster);
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $this->updateableColumns['pkey'] = 'required|integer';
        $this->updateableColumns['cluster'] = 'required|exists:cluster,pkey';

        $conference = new Conference;

        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $conference, $clusterShortuid) {
            $pkey = $request->input('pkey');
            if ($pkey !== null && Conference::where('pkey', $pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That room number is already in use in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $conference, $this->updateableColumns);
        $conference->cluster = $clusterShortuid;
        $conference->id = generate_ksuid();
        $conference->shortuid = generate_shortuid();

        // Normalise pin/adminpin: store as string; accept integer from client
        if ($request->has('pin') && is_numeric($request->input('pin'))) {
            $conference->pin = (string) $request->input('pin');
        }
        if ($request->has('adminpin') && is_numeric($request->input('adminpin'))) {
            $conference->adminpin = (string) $request->input('adminpin');
        }

        try {
            $conference->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $conference;
    }

    public function update(Request $request, Conference $conference)
    {
        $validator = Validator::make($request->all(), $this->updateableColumns);

        $validator->after(function ($validator) use ($request, $conference) {
            $pkeySubmitted = $request->input('pkey');
            if ($pkeySubmitted !== null && (string) $pkeySubmitted !== (string) $conference->getAttribute('pkey')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster')) ?? $conference->cluster;
                if ($clusterShortuid !== null && Conference::where('pkey', $pkeySubmitted)->where('cluster', $clusterShortuid)->where('id', '!=', $conference->id)->exists()) {
                    $validator->errors()->add('pkey', 'That room number is already in use in this tenant.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $conference, $this->updateableColumns);

        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $conference->cluster = $clusterShortuid;
        }

        if ($request->has('pin') && is_numeric($request->input('pin'))) {
            $conference->pin = (string) $request->input('pin');
        }
        if ($request->has('adminpin') && is_numeric($request->input('adminpin'))) {
            $conference->adminpin = (string) $request->input('adminpin');
        }

        try {
            if ($conference->isDirty()) {
                $id = $conference->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Conference id is missing'], 409);
                }
                $dirty = $conference->getDirty();
                Conference::where('id', $id)->update($dirty);
                $conference->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($conference, 200);
    }

    public function delete(Conference $conference)
    {
        $conference->delete();
        return response()->json(null, 204);
    }
}
