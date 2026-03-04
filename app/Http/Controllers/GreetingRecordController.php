<?php

namespace App\Http\Controllers;

use App\Models\Greeting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class GreetingRecordController extends Controller
{
    /**
     * greeting table (sqlite_create_tenant.sql). Tenant-scoped.
     *
     * - pkey is identity-only after create; treat as integer at API boundary.
     * - filename stores original uploaded name (display/reference).
     * - saved file is /usr/share/asterisk/sounds/{clusterShortuid}/usergreeting{pkey}.{type}
     */
    private $updateableColumns = [
        // pkey is identity-only in this resource; not updateable (set on create only)
        'cluster' => 'exists:cluster,pkey',
        'cname' => 'string|nullable',
        'description' => 'string|nullable',
    ];

    /** Return column names that are updateable (for schema metadata). */
    public function getUpdateableColumns(): array
    {
        return array_keys($this->updateableColumns);
    }

    public function index(Greeting $greeting)
    {
        return Greeting::orderBy('pkey', 'asc')->get();
    }

    public function show(Greeting $greetingrecord)
    {
        return response()->json($greetingrecord, 200);
    }

    /** Download greeting audio for this DB row. */
    public function download(Greeting $greetingrecord)
    {
        $clusterShortuid = $greetingrecord->cluster;
        $pkey = $greetingrecord->pkey;
        $type = $greetingrecord->type;

        if ($clusterShortuid === null || $clusterShortuid === '' || $pkey === null || $pkey === '' || $type === null || $type === '') {
            return Response::json(['Error' => 'Greeting record missing cluster/pkey/type'], 409);
        }

        $saved = "usergreeting{$pkey}.{$type}";
        $rel = "{$clusterShortuid}/{$saved}";

        // Disk root should be /usr/share/asterisk/sounds; rel includes cluster subdir.
        if (!Storage::disk('greetings')->exists($rel)) {
            return Response::json(['Error' => 'Greeting audio file not found'], 404);
        }

        return Storage::disk('greetings')->download($rel, $saved);
    }

    /** Create row + upload file. */
    public function save(Request $request)
    {
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid === null) {
            return response()->json(['cluster' => ['Invalid or missing cluster.']], 422);
        }

        // Create rules: require pkey + file; pkey treated as integer.
        $rules = array_merge($this->updateableColumns, [
            'pkey' => 'required|integer|min:1',
            'cluster' => 'required|exists:cluster,pkey',
            'greeting' => 'required|file|mimes:wav,mp3',
        ]);

        $greeting = new Greeting;

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $clusterShortuid) {
            $pkey = $request->input('pkey');
            if ($pkey !== null && Greeting::where('pkey', $pkey)->where('cluster', $clusterShortuid)->exists()) {
                $validator->errors()->add('pkey', 'That greeting number is already in use in this tenant.');
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Move allowed request fields to model (cluster resolved separately)
        move_request_to_model($request, $greeting, $this->updateableColumns);
        $greeting->cluster = $clusterShortuid;

        // Identity
        $greeting->id = generate_ksuid();
        $greeting->shortuid = generate_shortuid();
        $greeting->pkey = (string) ((int) $request->input('pkey'));

        // Upload handling
        $file = $request->file('greeting');
        $original = $file ? $file->getClientOriginalName() : null;
        $ext = $file ? strtolower($file->getClientOriginalExtension() ?: '') : '';
        $type = $ext === 'mp3' ? 'mp3' : ($ext === 'wav' ? 'wav' : '');
        if ($type === '') {
            return Response::json(['greeting' => ['Invalid file type (must be wav or mp3).']], 422);
        }
        $greeting->filename = $original; // store original upload name
        $greeting->type = $type;

        $saved = "usergreeting{$greeting->pkey}.{$type}";
        $clusterDir = $clusterShortuid;
        $rel = "{$clusterDir}/{$saved}";

        try {
            // Ensure tenant subdir exists on disk root
            Storage::disk('greetings')->makeDirectory($clusterDir);

            // Write file to disk (storage/app/greetings... not used here; we write directly to disk root)
            Storage::disk('greetings')->putFileAs($clusterDir, $file, $saved);

            // Best-effort permissions (matches existing approach; no error if it fails)
            @shell_exec("/bin/chown asterisk:asterisk " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
            @shell_exec("/bin/chmod 664 " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
        } catch (\Throwable $e) {
            return Response::json(['Error' => 'Failed to save greeting audio: ' . $e->getMessage()], 409);
        }

        try {
            $greeting->save();
        } catch (\Exception $e) {
            // Roll back file if DB insert fails
            try {
                Storage::disk('greetings')->delete($rel);
            } catch (\Throwable $ignored) {
            }
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return $greeting;
    }

    /** Update metadata and optionally replace audio. */
    public function update(Request $request, Greeting $greetingrecord)
    {
        $rules = array_merge($this->updateableColumns, [
            'cluster' => 'exists:cluster,pkey',
            'greeting' => 'file|mimes:wav,mp3',
        ]);

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $greetingrecord) {
            // cluster changes: ensure pkey remains unique in target tenant (identity-only pkey)
            if ($request->has('cluster')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
                if ($clusterShortuid !== null) {
                    if (Greeting::where('pkey', $greetingrecord->pkey)->where('cluster', $clusterShortuid)->where('id', '!=', $greetingrecord->id)->exists()) {
                        $validator->errors()->add('cluster', 'That tenant already has a greeting with this number.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $greetingrecord, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $greetingrecord->cluster = $clusterShortuid;
        }

        // If replacing audio, update filename/type and write new file.
        if ($request->hasFile('greeting')) {
            $file = $request->file('greeting');
            $original = $file ? $file->getClientOriginalName() : null;
            $ext = $file ? strtolower($file->getClientOriginalExtension() ?: '') : '';
            $type = $ext === 'mp3' ? 'mp3' : ($ext === 'wav' ? 'wav' : '');
            if ($type === '') {
                return Response::json(['greeting' => ['Invalid file type (must be wav or mp3).']], 422);
            }

            $greetingrecord->filename = $original;
            $greetingrecord->type = $type;

            $saved = "usergreeting{$greetingrecord->pkey}.{$type}";
            $clusterDir = $greetingrecord->cluster;
            $rel = "{$clusterDir}/{$saved}";

            try {
                Storage::disk('greetings')->makeDirectory($clusterDir);
                Storage::disk('greetings')->putFileAs($clusterDir, $file, $saved);
                @shell_exec("/bin/chown asterisk:asterisk " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
                @shell_exec("/bin/chmod 664 " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
            } catch (\Throwable $e) {
                return Response::json(['Error' => 'Failed to replace greeting audio: ' . $e->getMessage()], 409);
            }
        }

        try {
            if ($greetingrecord->isDirty()) {
                $id = $greetingrecord->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Greeting id is missing'], 409);
                }
                $dirty = $greetingrecord->getDirty();
                Greeting::where('id', $id)->update($dirty);
                $greetingrecord->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($greetingrecord, 200);
    }

    /**
     * Replace audio (multipart POST) and optionally update metadata.
     * SPA uses this endpoint because putFile() helper is POST-only.
     */
    public function replace(Request $request, Greeting $greetingrecord)
    {
        $rules = array_merge($this->updateableColumns, [
            'cluster' => 'exists:cluster,pkey',
            'greeting' => 'required|file|mimes:wav,mp3',
        ]);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $greetingrecord) {
            if ($request->has('cluster')) {
                $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
                if ($clusterShortuid !== null) {
                    if (Greeting::where('pkey', $greetingrecord->pkey)->where('cluster', $clusterShortuid)->where('id', '!=', $greetingrecord->id)->exists()) {
                        $validator->errors()->add('cluster', 'That tenant already has a greeting with this number.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        move_request_to_model($request, $greetingrecord, $this->updateableColumns);
        $clusterShortuid = cluster_identifier_to_shortuid($request->input('cluster'));
        if ($clusterShortuid !== null) {
            $greetingrecord->cluster = $clusterShortuid;
        }

        $file = $request->file('greeting');
        $original = $file ? $file->getClientOriginalName() : null;
        $ext = $file ? strtolower($file->getClientOriginalExtension() ?: '') : '';
        $type = $ext === 'mp3' ? 'mp3' : ($ext === 'wav' ? 'wav' : '');
        if ($type === '') {
            return Response::json(['greeting' => ['Invalid file type (must be wav or mp3).']], 422);
        }

        $greetingrecord->filename = $original;
        $greetingrecord->type = $type;

        $saved = "usergreeting{$greetingrecord->pkey}.{$type}";
        $clusterDir = $greetingrecord->cluster;
        $rel = "{$clusterDir}/{$saved}";

        try {
            Storage::disk('greetings')->makeDirectory($clusterDir);
            Storage::disk('greetings')->putFileAs($clusterDir, $file, $saved);
            @shell_exec("/bin/chown asterisk:asterisk " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
            @shell_exec("/bin/chmod 664 " . escapeshellarg("/usr/share/asterisk/sounds/{$rel}"));
        } catch (\Throwable $e) {
            return Response::json(['Error' => 'Failed to replace greeting audio: ' . $e->getMessage()], 409);
        }

        try {
            if ($greetingrecord->isDirty()) {
                $id = $greetingrecord->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Greeting id is missing'], 409);
                }
                $dirty = $greetingrecord->getDirty();
                Greeting::where('id', $id)->update($dirty);
                $greetingrecord->syncOriginal();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($greetingrecord, 200);
    }

    public function delete(Greeting $greetingrecord)
    {
        // Best-effort delete of audio file (derived from row)
        try {
            $cluster = $greetingrecord->cluster;
            $pkey = $greetingrecord->pkey;
            $type = $greetingrecord->type;
            if ($cluster && $pkey && $type) {
                $saved = "usergreeting{$pkey}.{$type}";
                Storage::disk('greetings')->delete("{$cluster}/{$saved}");
            }
        } catch (\Throwable $ignored) {
        }

        $greetingrecord->delete();
        return response()->json(null, 204);
    }
}

