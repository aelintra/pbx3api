<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk config files in /etc/asterisk: list, view, edit.
 * Read-only list is hardcoded (see DATA_DRIVEN_LIST_POLICY_PROJECT.md for future data-driven approach).
 */
class AsteriskFileController extends Controller
{
    /** Filenames that are view-only (not editable) in the UI. */
    private const READONLY_FILES = [
        'agents.conf',
        'dahdi-channels.conf',
        'extensions.conf',
        'features.conf',
        'iax.conf',
        'queues.conf',
        'sip.conf',
        'sark_agents_main.conf',
        'sark_iax_localnet_header.conf',
        'sark_iax_main.conf',
        'sark_iax_registrations.conf',
        'sark_meetme.conf',
        'sark_queues_main.conf',
        'sark_sip_localnet_header.conf',
        'sark_sip_main.conf',
        'sark_sip_registrations.conf',
        'cdr_mysql.conf',
    ];

    private const ASTERISK_DIR = '/etc/asterisk';

    /** Safe filename: letters, numbers, underscore, hyphen, dot only (no path traversal). */
    private static function isValidFilename(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..'
            && preg_match('/^[a-zA-Z0-9_.-]+$/', $name) === 1;
    }

    /**
     * List files in /etc/asterisk with readonly flag.
     */
    public function index()
    {
        [$output, $err] = pbx3_request_syscmd('ls -1 ' . self::ASTERISK_DIR . ' 2>/dev/null');
        if ($err !== null) {
            return response()->json(['message' => 'Failed to list Asterisk directory', 'detail' => $err], 502);
        }
        $readonlySet = array_fill_keys(self::READONLY_FILES, true);
        $lines = array_filter(preg_split('/\r?\n/', $output ?? ''));
        $files = [];
        foreach ($lines as $line) {
            $name = trim($line);
            if (!self::isValidFilename($name)) {
                continue;
            }
            $files[] = [
                'filename' => $name,
                'readonly' => isset($readonlySet[$name]),
            ];
        }
        sort($files);
        return response()->json(['files' => $files], 200);
    }

    /**
     * Get contents of one file and readonly flag.
     */
    public function show(string $filename)
    {
        if (!self::isValidFilename($filename)) {
            return response()->json(['message' => 'Invalid filename'], 422);
        }
        $path = self::ASTERISK_DIR . '/' . $filename;
        [$output, $err] = pbx3_request_syscmd('cat ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($err !== null) {
            return response()->json(['message' => 'Failed to read file', 'detail' => $err], 502);
        }
        $readonly = in_array($filename, self::READONLY_FILES, true);
        return response()->json([
            'filename' => $filename,
            'content' => $output ?? '',
            'readonly' => $readonly,
        ], 200);
    }

    /**
     * Save file contents. Rejects if file is in readonly list.
     */
    public function update(Request $request, string $filename)
    {
        if (!self::isValidFilename($filename)) {
            return response()->json(['message' => 'Invalid filename'], 422);
        }
        if (in_array($filename, self::READONLY_FILES, true)) {
            return response()->json(['message' => 'This file is read-only and cannot be edited'], 403);
        }
        $content = $request->input('content');
        if ($content === null) {
            return response()->json(['message' => 'content is required'], 422);
        }
        $tmpName = 'astfile_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '_' . time();
        $tmpPath = '/tmp/' . $tmpName;
        $written = @file_put_contents($tmpPath, $content);
        if ($written === false) {
            return response()->json(['message' => 'Failed to write temporary file'], 500);
        }
        $path = self::ASTERISK_DIR . '/' . $filename;
        [$response, $err] = pbx3_request_syscmd('/bin/mv ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($path));
        if ($err !== null) {
            @unlink($tmpPath);
            Log::error("AsteriskFileController: failed to move file via syshelper: $err");
            return response()->json(['message' => 'Save failed', 'detail' => $err], 502);
        }
        pbx3_request_syscmd('/usr/bin/dos2unix ' . escapeshellarg($path) . ' 2>/dev/null');
        pbx3_request_syscmd('/bin/chown root:asterisk ' . escapeshellarg($path) . ' 2>/dev/null');
        pbx3_request_syscmd('/bin/chmod 640 ' . escapeshellarg($path) . ' 2>/dev/null');
        return response()->json(['message' => 'Updated'], 200);
    }
}
