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

	/** Safe log path: no path traversal. */
	private static function isValidLogPath(string $path): bool
	{
		return $path !== '' && $path !== '.' && $path !== '..'
			&& strpos($path, '..') === false
			&& strpos($path, '/') !== 0; // Must be relative
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
				
				$logs[] = [
					'path' => $logPath,
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
	public function show(Request $request, string $logfile = null)
	{
		// Reconstruct full path from request URI since Laravel router splits on /
		// Get the path info (e.g., /api/logs/asterisk/messages)
		$pathInfo = $request->getPathInfo();
		$logfile = null;
		
		// Try to extract from pathInfo first (handles paths with slashes)
		if (preg_match('#^/api/logs/(.+)$#', $pathInfo, $matches)) {
			$logfile = urldecode($matches[1]);
		} elseif (preg_match('#^/logs/(.+)$#', $pathInfo, $matches)) {
			$logfile = urldecode($matches[1]);
		}
		
		// Fallback to route parameter (for simple paths without slashes like 'syslog')
		if (!$logfile) {
			$logfile = $request->route('logfile');
		}
		
		$logfile = $logfile ?? '';
		
		if (!self::isValidLogPath($logfile)) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}

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

		return response()->json([
			'path' => $logfile,
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
	public function download(Request $request, string $logfile = null)
	{
		// Reconstruct full path from request URI since Laravel router splits on /
		$pathInfo = $request->getPathInfo();
		$logfile = null;
		
		// Try to extract from pathInfo first (handles paths with slashes)
		if (preg_match('#^/api/logs/(.+)/download$#', $pathInfo, $matches)) {
			$logfile = urldecode($matches[1]);
		} elseif (preg_match('#^/logs/(.+)/download$#', $pathInfo, $matches)) {
			$logfile = urldecode($matches[1]);
		}
		
		// Fallback to route parameter (for simple paths without slashes)
		if (!$logfile) {
			$logfile = $request->route('logfile');
		}
		
		$logfile = $logfile ?? '';
		
		if (!self::isValidLogPath($logfile)) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}

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

		return Response::download($tmpPath, basename($logfile))->deleteFileAfterSend(true);
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
