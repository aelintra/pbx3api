<?php

namespace App\Http\Controllers;

use App\Models\HolidayTimer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class HolidayTimerController extends Controller
{
    // holiday table. pkey = system-generated text. cluster stored as shortuid. stime/etime = epoch.
    private $updateableColumns = [
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
        'route' => 'string|nullable',
        'stime' => 'integer|nullable',
        'etime' => 'integer|nullable',
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(HolidayTimer $holidaytimer)
    {
        return HolidayTimer::orderBy('stime')->orderBy('id')->get();
    }

    public function show(HolidayTimer $holidaytimer)
    {
        return response()->json($holidaytimer, 200);
    }

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
        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            $stime = $this->stimeFromRequest($request);
            $etime = $this->etimeFromRequest($request);
            if ($stime !== null && $etime !== null && $etime < $stime) {
                $validator->errors()->add('etime', 'End time must be after start time.');
            }
            if ($stime !== null && $etime !== null && $this->overlapsExisting($clusterShortuid, $stime, $etime, null)) {
                $validator->errors()->add('stime', 'This period overlaps an existing holiday in the same tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $holidaytimer = new HolidayTimer;
        move_request_to_model($request, $holidaytimer, $this->updateableColumns);
        $holidaytimer->cluster = $clusterShortuid;
        $holidaytimer->id = generate_ksuid();
        $holidaytimer->shortuid = generate_shortuid();
        $holidaytimer->pkey = 'sched' . rand(100000, 999999);

        $stime = $this->stimeFromRequest($request);
        $etime = $this->etimeFromRequest($request);
        if ($stime === null) {
            $stime = Carbon::today()->startOfDay()->timestamp;
        }
        if ($etime === null) {
            $etime = Carbon::today()->startOfDay()->timestamp;
        }
        if ($etime < $stime) {
            $etime = $stime;
        }
        $holidaytimer->stime = $stime;
        $holidaytimer->etime = $etime;

        try {
            $holidaytimer->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $holidaytimer;
    }

    public function update(Request $request, HolidayTimer $holidaytimer)
    {
        $validator = Validator::make($request->all(), $this->updateableColumns);
        $validator->after(function ($validator) use ($request, $holidaytimer) {
            $stime = $this->stimeFromRequest($request) ?? $holidaytimer->stime;
            $etime = $this->etimeFromRequest($request) ?? $holidaytimer->etime;
            if ($stime !== null && $etime !== null && $etime < $stime) {
                $validator->errors()->add('etime', 'End time must be after start time.');
            }
            $cluster = $holidaytimer->cluster;
            if ($request->has('cluster')) {
                $resolved = cluster_identifier_to_shortuid($request->input('cluster'));
                if ($resolved !== null) {
                    $cluster = $resolved;
                }
            }
            if ($stime !== null && $etime !== null && $this->overlapsExisting($cluster, $stime, $etime, $holidaytimer->id)) {
                $validator->errors()->add('stime', 'This period overlaps an existing holiday in the same tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $holidaytimer, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $holidaytimer->cluster = $clusterShortuid;
        }

        $stime = $this->stimeFromRequest($request);
        $etime = $this->etimeFromRequest($request);
        if ($stime !== null) {
            $holidaytimer->stime = $stime;
        }
        if ($etime !== null) {
            $holidaytimer->etime = $etime;
        }

        try {
            if ($holidaytimer->isDirty()) {
                $id = $holidaytimer->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Holiday timer id is missing'], 409);
                }
                $dirty = $holidaytimer->getDirty();
                HolidayTimer::where('id', $id)->update($dirty);
                $holidaytimer->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($holidaytimer, 200);
    }

    public function delete(HolidayTimer $holidaytimer)
    {
        $holidaytimer->delete();
        return response()->json(null, 204);
    }

    private function stimeFromRequest(Request $request): ?int
    {
        $v = $request->input('stime');
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (int) $v : null;
    }

    private function etimeFromRequest(Request $request): ?int
    {
        $v = $request->input('etime');
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (int) $v : null;
    }

    /** Check if (stime, etime) overlaps any row in cluster excluding excludeId. Overlap: new_stime < existing_etime AND new_etime > existing_stime. */
    private function overlapsExisting(string $clusterShortuid, int $stime, int $etime, ?string $excludeId): bool
    {
        $q = HolidayTimer::where('cluster', $clusterShortuid)
            ->whereRaw('? < etime AND stime < ?', [$stime, $etime]);
        if ($excludeId !== null && $excludeId !== '') {
            $q->where('id', '!=', $excludeId);
        }
        return $q->exists();
    }
}
