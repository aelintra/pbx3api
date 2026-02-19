<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class LogController extends Controller
{

	private $updateableColumns = [];

	/** List of log files to show (relative to /var/log/). */
	private const LOG_FILES = [
		'asterisk/messages',
		'asterisk/full',
		'asterisk/cdr-csv/Master.csv',
		'asterisk/queue_log',
		'syslog',
		'shorewall.log',
		'siplog',
		'mail.log',
		'fail2ban.log',
		'auth.log',
	];

	/** Map symbolic names to actual log file paths (relative to /var/log/). */
	private const LOG_FILE_MAP = [
		'astmessages' => 'asterisk/messages',
		'astfull' => 'asterisk/full',
		'astcdrs' => 'asterisk/cdr-csv/Master.csv',
		'astqueues' => 'asterisk/queue_log',
	];

	/** Safe log path: no path traversal. */
	private static function isValidLogPath(string $path): bool
	{
		return $path !== '' && $path !== '.' && $path !== '..'
			&& strpos($path, '..') === false
			&& strpos($path, '/') !== 0; // Must be relative
	}

	/**
	 * Resolve symbolic name to actual file path.
	 * Returns the mapped path if name is in LOG_FILE_MAP, otherwise returns the name as-is.
	 */
	private static function resolveLogPath(string $name): string
	{
		return self::LOG_FILE_MAP[$name] ?? $name;
	}

	/**
	 * Check if a log name/path is valid (either a symbolic name or a direct path in LOG_FILES).
	 */
	private static function isValidLogName(string $name): bool
	{
		// Check if it's a symbolic name
		if (isset(self::LOG_FILE_MAP[$name])) {
			return true;
		}
		// Check if it's a direct path in LOG_FILES
		if (in_array($name, self::LOG_FILES, true)) {
			return self::isValidLogPath($name);
		}
		return false;
	}

	/**
	 * Get display name for log file (symbolic name if mapped, otherwise path).
	 */
	private static function getLogDisplayName(string $logPath): string
	{
		$symbolic = array_search($logPath, self::LOG_FILE_MAP, true);
		return $symbolic !== false ? $symbolic : $logPath;
	}

	/**
	 * List log files with metadata (exists, size).
	 */
	public function index()
	{
		try {
			$logs = [];
			foreach (self::LOG_FILES as $logPath) {
				$fullPath = '/var/log/' . $logPath;
				
				// Check if file exists
				[$testOut, $testErr] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullPath) . ' && echo exists || echo missing');
				$exists = ($testErr === null && trim($testOut) === 'exists');
				
				// Get file size if exists
				$size = 0;
				if ($exists) {
					[$sizeOut, $sizeErr] = pbx3_request_syscmd('stat -c %s ' . escapeshellarg($fullPath) . ' 2>/dev/null');
					if ($sizeErr === null && is_numeric(trim($sizeOut))) {
						$size = (int)trim($sizeOut);
					}
				}
				
				// Use symbolic name if mapped, otherwise use path
				$displayName = self::getLogDisplayName($logPath);
				
				$logs[] = [
					'path' => $displayName, // Return symbolic name for asterisk logs
					'actualPath' => $logPath, // Keep actual path for reference
					'exists' => $exists,
					'size' => $size,
				];
			}
			return response()->json(['logs' => $logs], 200);
		} catch (\Exception $e) {
			Log::error('LogController::index failed', ['error' => $e->getMessage()]);
			return response()->json(['message' => 'Failed to list logs', 'detail' => $e->getMessage()], 500);
		}
	}

	/**
	 * Get paginated log lines.
	 * offset=0 means last N lines (tail), offset>0 means older lines.
	 * 
	 * @param Request $request
	 * @param string $logfile Log file path (relative to /var/log/) - may be partial if route split on /
	 */
	public function show(Request $request, string $logfile)
	{
		// Validate the log name (symbolic or direct path)
		if (!self::isValidLogName($logfile)) {
			return response()->json(['message' => 'Invalid log name'], 422);
		}
		
		// Resolve symbolic name to actual path (e.g., astmessages → asterisk/messages)
		$actualPath = self::resolveLogPath($logfile);
		
		if (!self::isValidLogPath($actualPath)) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}
		
		// Use actual path for file operations
		$logfile = $actualPath;

		$validator = Validator::make($request->all(), [
			'offset' => 'integer|min:0',
			'limit' => 'integer|min:1|max:1000',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		$offset = (int) $request->input('offset', 0);
		$limit = (int) $request->input('limit', 100);
		$fullPath = '/var/log/' . $logfile;

		// Check file exists
		[$testOut, $testErr] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullPath) . ' && echo exists || echo missing');
		if ($testErr !== null || trim($testOut) !== 'exists') {
			return response()->json(['message' => 'Log file not found'], 404);
		}

		// Count total lines
		[$lineCountOut, $lineCountErr] = pbx3_request_syscmd('wc -l < ' . escapeshellarg($fullPath) . ' 2>/dev/null');
		$totalLines = $lineCountErr === null ? (int)trim($lineCountOut) : 0;

		// Read lines
		$lines = [];
		if ($totalLines > 0) {
			if ($offset === 0) {
				// Tail: last N lines
				[$output, $err] = pbx3_request_syscmd('tail -n ' . $limit . ' ' . escapeshellarg($fullPath) . ' 2>/dev/null');
			} else {
				// Older lines: from line (totalLines - offset - limit + 1) to (totalLines - offset)
				$startLine = max(1, $totalLines - $offset - $limit + 1);
				$endLine = $totalLines - $offset;
				if ($startLine <= $endLine && $endLine > 0) {
					[$output, $err] = pbx3_request_syscmd('sed -n "' . $startLine . ',' . $endLine . 'p" ' . escapeshellarg($fullPath) . ' 2>/dev/null');
				} else {
					$output = '';
					$err = null;
				}
			}

			if ($err === null && $output !== null) {
				$lines = array_filter(preg_split('/\r?\n/', $output), function($line) {
					return $line !== '';
				});
			}
		}

		$hasMore = ($offset + $limit) < $totalLines;

		// Return symbolic name if mapped, otherwise actual path
		$displayName = self::getLogDisplayName($logfile);

		return response()->json([
			'path' => $displayName,
			'lines' => $lines,
			'offset' => $offset,
			'limit' => $limit,
			'totalLines' => $totalLines,
			'hasMore' => $hasMore,
		], 200);
	}

	/**
	 * Download full log file.
	 * 
	 * @param Request $request
	 * @param string $logfile Log file path (relative to /var/log/) - may be partial if route split on /
	 */
	public function download(Request $request, string $logfile)
	{
		// Validate the log name (symbolic or direct path)
		if (!self::isValidLogName($logfile)) {
			return response()->json(['message' => 'Invalid log name'], 422);
		}
		
		// Resolve symbolic name to actual path (e.g., astmessages → asterisk/messages)
		$actualPath = self::resolveLogPath($logfile);
		
		if (!self::isValidLogPath($actualPath)) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}
		
		// Use actual path for file operations
		$logfile = $actualPath;

		$fullPath = '/var/log/' . $logfile;
		[$testOut, $testErr] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullPath) . ' && echo exists || echo missing');
		if ($testErr !== null || trim($testOut) !== 'exists') {
			return response()->json(['message' => 'Log file not found'], 404);
		}

		// Copy to temp file for download (syshelper can read, but Response::download needs readable file)
		$tmpName = 'log_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($logfile)) . '_' . time();
		$tmpPath = '/tmp/' . $tmpName;
		[$copyOut, $copyErr] = pbx3_request_syscmd('/bin/cp ' . escapeshellarg($fullPath) . ' ' . escapeshellarg($tmpPath) . ' 2>&1');
		if ($copyErr !== null || !file_exists($tmpPath)) {
			return response()->json(['message' => 'Failed to prepare download', 'detail' => $copyErr ?? 'Copy failed'], 502);
		}

		// Make readable
		@chmod($tmpPath, 0644);

		// Use symbolic name for download filename if mapped
		$displayName = self::getLogDisplayName($logfile);
		$downloadName = strpos($displayName, '/') === false ? basename($logfile) : basename($logfile);

		return Response::download($tmpPath, $downloadName)->deleteFileAfterSend(true);
	}

/**
 * Return (Download) CDR
 * 
 * @param  REQUEST
 * @return csv file
 */
	public function showcdr(Request $request)
	{
		// Validate         
		$validator = Validator::make($request->all(), [         
			'limit' => 'numeric',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		$dname = "/tmp/Master." . time() . ".csv";
		$cmd = "/bin/cat";
		if (isset($request->limit)) {
			$cmd = "/usr/bin/tail -n $limit";
		}
	   
		shell_exec(" $cmd /var/log/asterisk/cdr-csv/Master.csv > $dname");

		return Response::download($dname)->deleteFileAfterSend(true);
	}
}
