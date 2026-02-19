<?php

namespace App\Http\Controllers;

use App\Models\HelpCore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Help messages (tt_help_core). Instance-scoped; identity by pkey only (no id/shortuid).
 */
class HelpCoreController extends Controller
{
    /** Updateable columns (name is deprecated; we only expose displayname, htext, cname). */
    private $updateableColumns = [
        'displayname' => 'string|nullable',
        'htext' => 'string|nullable',
        'cname' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        return HelpCore::orderBy('pkey', 'asc')->get();
    }

    public function show(HelpCore $helpcore)
    {
        return response()->json($helpcore, 200);
    }

    public function save(Request $request)
    {
        $rules = array_merge(['pkey' => 'required|string'], $this->updateableColumns);
        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if (HelpCore::where('pkey', '=', $request->pkey)->exists()) {
                $validator->errors()->add('pkey', 'Duplicate key: ' . $request->pkey);
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $helpcore = new HelpCore();
        move_request_to_model($request, $helpcore, array_merge(['pkey' => ''], $this->updateableColumns));
        $helpcore->pkey = trim((string) $request->pkey);

        try {
            $helpcore->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($helpcore, 201);
    }

    public function update(Request $request, HelpCore $helpcore)
    {
        $validator = Validator::make($request->all(), $this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $helpcore, $this->updateableColumns);

        try {
            if ($helpcore->isDirty()) {
                $dirty = $helpcore->getDirty();
                HelpCore::where('pkey', $helpcore->pkey)->update($dirty);
                $helpcore->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($helpcore->fresh(), 200);
    }

    public function delete(HelpCore $helpcore)
    {
        $helpcore->delete();

        return response()->json(null, 204);
    }
}
