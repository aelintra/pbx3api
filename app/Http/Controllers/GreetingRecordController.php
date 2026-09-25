<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesClusterScope;
use App\Models\Greeting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class GreetingRecordController extends Controller
{
    use EnforcesClusterScope;

    /**
     * greeting table (sqlite_create_tenant.sql). Tenant-scoped.
     *
     * - pkey is identity-only after create; treat as integer at API boundary.
     * - filename stores original uploaded name (display/reference).
     * - saved file is /usr/share/asterisk/sounds/{clusterShortuid}/usergreeting{pkey}.{type}
     */
    private const GREETINGS_ROOT = '/usr/share/asterisk/sounds';

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

    /**
     * Ensure tenant sounds subdir exists (via syshelper; API cannot mkdir there directly).
     * Creates directory and sets ownership so www-data can write.
     */
    private function ensureGreetingTenantDir(string $clusterDir): void
    {
        $fullPath = self::GREETINGS_ROOT . '/' . $clusterDir;
        [$out, $err] = pbx3_request_syscmd('/bin/mkdir -p ' . escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to create tenant directory: ' . $err);
        }
        [$out, $err] = pbx3_request_syscmd('/bin/chown www-data:www-data ' . escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to set tenant directory ownership: ' . $err);
        }
        [$out, $err] = pbx3_request_syscmd('/bin/chmod 755 ' . escapeshellarg($fullPath));
        if ($err !== null) {
            throw new \RuntimeException('Unable to set tenant directory permissions: ' . $err);
        }
    }

    public function index(Greeting $greeting)
    {
        return $this->applyClusterScope(Greeting::query())->orderBy('pkey', 'asc')->get();
    }

    /** Export greetings list as PDF. Same dataset as index with tenant_pkey resolved. */
    public function exportPdf()
    {
        $greetings = $this->applyClusterScope(Greeting::query())->orderBy('pkey', 'asc')->get();
        attach_tenant_pkey_to_collection($greetings);
        return Pdf::loadView('exports.greetings-pdf', ['greetings' => $greetings])
            ->setPaper('a4', 'landscape')
            ->download('greetings.pdf');
    }

    public function show(Greeting $greetingrecord)
    {
        $this->assertModelClusterAllowed($greetingrecord);
        return response()->json($greetingrecord, 200);
    }

    /** Download greeting audio for this DB row. */
    public function download(Greeting $greetingrecord)
    {
        $this->assertModelClusterAllowed($greetingrecord);
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
        $this->assertClusterAllowed($clusterShortuid);

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
        $greeting->type = 'wav';

        $clusterDir = $clusterShortuid;
        $rel = "{$clusterDir}/usergreeting{$greeting->pkey}.wav";
        $err = $this->storeAsteriskGreeting($file, $clusterDir, $greeting->pkey);
        if ($err !== null) {
            return Response::json(['greeting' => [$err]], 422);
        }

        try {
            $greeting->save();
            set_commit_dirty();
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
        $this->assertModelClusterAllowed($greetingrecord);
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
            $this->assertClusterAllowed($clusterShortuid);
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
            $clusterDir = $greetingrecord->cluster;
            $err = $this->storeAsteriskGreeting($file, $clusterDir, $greetingrecord->pkey);
            if ($err !== null) {
                return Response::json(['greeting' => [$err]], 422);
            }
            $greetingrecord->type = 'wav';
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
                set_commit_dirty();
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
        $this->assertModelClusterAllowed($greetingrecord);
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
            $this->assertClusterAllowed($clusterShortuid);
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
        $clusterDir = $greetingrecord->cluster;
        $err = $this->storeAsteriskGreeting($file, $clusterDir, $greetingrecord->pkey);
        if ($err !== null) {
            return Response::json(['greeting' => [$err]], 422);
        }
        $greetingrecord->type = 'wav';

        try {
            if ($greetingrecord->isDirty()) {
                $id = $greetingrecord->id;
                if ($id === null || $id === '') {
                    return Response::json(['Error' => 'Greeting id is missing'], 409);
                }
                $dirty = $greetingrecord->getDirty();
                Greeting::where('id', $id)->update($dirty);
                $greetingrecord->syncOriginal();
                set_commit_dirty();
            }
        } catch (\Exception $e) {
            return Response::json(['Error' => $e->getMessage()], 409);
        }

        return response()->json($greetingrecord, 200);
    }

    /**
     * Write a greeting as 8000 Hz, 16-bit, mono PCM WAV (Asterisk format_wav).
     * Returns an error string, or null on success.
     */
    private function storeAsteriskGreeting($file, string $clusterDir, string $pkey): ?string
    {
        $src = $file ? $file->getRealPath() : null;
        if (!$src || !is_readable($src)) {
            return 'Upload temporary file missing.';
        }

        $wav = sys_get_temp_dir().'/ug'.bin2hex(random_bytes(6)).'.wav';
        $cmd = '/usr/bin/sox '.escapeshellarg($src)
            .' -r 8000 -c 1 -b 16 -e signed-integer '.escapeshellarg($wav).' -q';
        exec($cmd.' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($wav) || filesize($wav) === 0) {
            @unlink($wav);
            return 'Could not convert audio to 8000 Hz, 16-bit, mono WAV.';
        }

        $rel = "{$clusterDir}/usergreeting{$pkey}.wav";
        try {
            $this->ensureGreetingTenantDir($clusterDir);
            $written = Storage::disk('greetings')->put($rel, file_get_contents($wav));
            if ($written === false) {
                return 'Failed to save greeting audio.';
            }
            $full = "/usr/share/asterisk/sounds/{$rel}";
            @shell_exec('/bin/chown asterisk:asterisk '.escapeshellarg($full));
            @shell_exec('/bin/chmod 664 '.escapeshellarg($full));
        } catch (\Throwable $e) {
            return 'Failed to save greeting audio: '.$e->getMessage();
        } finally {
            @unlink($wav);
        }

        return null;
    }

    public function delete(Greeting $greetingrecord)
    {
        $this->assertModelClusterAllowed($greetingrecord);
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
        set_commit_dirty();
        return response()->json(null, 204);
    }
}

