<?php

namespace App\Http\Controllers;

use App\Models\DayTimer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class DayTimerController extends Controller
{
    // dateseg table. pkey = INTEGER UNIQUE, system-generated on create. cluster stored as shortuid.
    private $updateableColumns = [
        'active' => 'in:YES,NO',
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'datemonth' => 'in:*,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31',
        'dayofweek' => 'in:*,mon,tue,wed,thu,fri,sat,sun',
        'description' => 'string|nullable',
        'month' => 'in:*,jan,feb,mar,apr,may,jun,jul,aug,sep,oct,nov,dec',
        'timespan' => [
            'regex:/^\*|(2[0-3]|[01][0-9]):([0-5][0-9])-(2[0-3]|[01][0-9]):([0-5][0-9])$/',
        ],
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(DayTimer $daytimer)
    {
        return DayTimer::orderBy('cluster')->orderBy('dayofweek')->orderBy('id')->get();
    }

    /**
     * Return DayTimer model instance (resolved by shortuid, id, or pkey).
     *
     * @param  DayTimer  $daytimer
     * @return DayTimer
     */
    public function show(DayTimer $daytimer)
    {
        return response()->json($daytimer, 200);
    }

    /**
     * Create a new DayTimer. Cluster required; pkey = unique integer.
     *
     * @return \Illuminate\Http\JsonResponse|DayTimer
     */
    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        $rules = array_merge($this->updateableColumns, [
            'cluster' => 'required|exists:cluster,pkey',
        ]);

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $daytimer = new DayTimer;
        move_request_to_model($request, $daytimer, $this->updateableColumns);
        $daytimer->cluster = $clusterShortuid;
        $daytimer->id = generate_ksuid();
        $daytimer->shortuid = generate_shortuid();
        $daytimer->pkey = $this->nextPkey();

        try {
            $daytimer->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $daytimer;
    }

    /**
     * Update DayTimer by id (dirty columns only). Resolve cluster to shortuid if provided.
     *
     * @param  DayTimer  $daytimer
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, DayTimer $daytimer)
    {
        $validator = Validator::make($request->all(), $this->updateableColumns);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $daytimer, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $daytimer->cluster = $clusterShortuid;
        }

        try {
            if ($daytimer->isDirty()) {
                $id = $daytimer->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Day timer id is missing'], 409);
                }
                $dirty = $daytimer->getDirty();
                DayTimer::where('id', $id)->update($dirty);
                $daytimer->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($daytimer, 200);
    }

    public function delete(DayTimer $daytimer)
    {
        $daytimer->delete();
        return response()->json(null, 204);
    }

    /**
     * Next unique integer for dateseg.pkey (schema: INTEGER UNIQUE).
     */
    private function nextPkey(): int
    {
        $max = DayTimer::max('pkey');
        if ($max !== null && is_numeric($max)) {
            return (int) $max + 1;
        }
        return (int) round(microtime(true) * 1000);
    }
}
