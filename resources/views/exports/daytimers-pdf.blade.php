<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Day timers report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 14px; margin-bottom: 0.5em; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f0f0f0; font-weight: 600; }
        .meta { color: #666; margin-bottom: 0.5em; }
    </style>
</head>
<body>
    <h1>Day timers</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($daytimers) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Active</th>
                <th>Start</th>
                <th>End</th>
                <th>Day of week</th>
                <th>Mode</th>
                <th>Pri</th>
                <th>Description</th>
                <th>State</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($daytimers as $d)
            @php
                $ts = trim((string) ($d->timespan ?? ''));
                if ($ts === '' || $ts === '*') {
                    $start = '*';
                    $end = '*';
                } else {
                    $dash = strpos($ts, '-');
                    if ($dash === false) {
                        $start = $ts;
                        $end = '*';
                    } else {
                        $start = trim(substr($ts, 0, $dash)) ?: '*';
                        $end = trim(substr($ts, $dash + 1)) ?: '*';
                    }
                }
                $dow = strtolower(trim((string) ($d->dayofweek ?? '')));
                if ($dow === '' || $dow === '*') {
                    $dowLabel = 'Every day';
                } elseif (preg_match('/^([a-z]{3})-([a-z]{3})$/', $dow, $m)) {
                    $dowLabel = ucfirst($m[1]) . '–' . ucfirst($m[2]);
                } else {
                    $dowLabel = ucfirst($dow);
                }
                $mode = trim((string) ($d->mode ?? ''));
                if ($mode === '') {
                    $mode = 'closed';
                }
            @endphp
            <tr>
                <td>{{ $d->tenant_pkey ?? $d->cluster ?? '—' }}</td>
                <td>{{ $d->active ?? '—' }}</td>
                <td>{{ $start }}</td>
                <td>{{ $end }}</td>
                <td>{{ $dowLabel }}</td>
                <td>{{ $mode }}</td>
                <td>{{ $d->priority ?? 0 }}</td>
                <td>{{ $d->description ?? '—' }}</td>
                <td>{{ $d->state ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
