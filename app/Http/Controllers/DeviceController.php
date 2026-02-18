<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Device (provisioning templates). Instance-scoped; identity by pkey only (no id/shortuid).
 */
class DeviceController extends Controller
{
    /** Updateable columns and validation rules (keys only for schema; full rules for save/update). */
    private $updateableColumns = [
        'blfkeyname' => 'string|nullable',
        'blfkeys' => 'integer|nullable',
        'desc' => 'string|nullable',
        'device' => 'string|nullable',
        'fkeys' => 'integer|nullable',
        'imageurl' => 'string|nullable',
        'legacy' => 'string|nullable',
        'noproxy' => 'string|nullable',
        'owner' => 'string|nullable',
        'pkeys' => 'integer|nullable',
        'provision' => 'string|nullable',
        'sipiaxfriend' => 'string|nullable',
        'technology' => 'string|nullable',
        'tftpname' => 'string|nullable',
        'zapdevfixed' => 'string|nullable',
    ];

    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index()
    {
        return Device::orderBy('pkey', 'asc')->get();
    }

    public function show(Device $device)
    {
        return response()->json($device, 200);
    }

    public function save(Request $request)
    {
        $rules = array_merge(['pkey' => 'required|string'], $this->updateableColumns);
        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if (Device::where('pkey', '=', $request->pkey)->exists()) {
                $validator->errors()->add('pkey', 'Duplicate key: ' . $request->pkey);
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $device = new Device();
        move_request_to_model($request, $device, array_merge(['pkey' => ''], $this->updateableColumns));
        $device->pkey = trim((string) $request->pkey);

        try {
            $device->save();
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($device, 201);
    }

    public function update(Request $request, Device $device)
    {
        $validator = Validator::make($request->all(), $this->updateableColumns);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $device, $this->updateableColumns);

        try {
            if ($device->isDirty()) {
                $dirty = $device->getDirty();
                Device::where('pkey', $device->pkey)->update($dirty);
                $device->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($device->fresh(), 200);
    }

    public function delete(Device $device)
    {
        $device->delete();

        return response()->json(null, 204);
    }
}
