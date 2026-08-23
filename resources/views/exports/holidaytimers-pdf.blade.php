<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Holiday timers report</title>
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
    <h1>Holiday timers</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($holidaytimers) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Start</th>
                <th>End</th>
                <th>Description</th>
                <th>Route</th>
                <th>State</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($holidaytimers as $h)
            @php
                $stime = is_numeric($h->stime) ? (int) $h->stime : null;
                $etime = is_numeric($h->etime) ? (int) $h->etime : null;
                $startLabel = $stime !== null ? date('Y-m-d H:i', $stime) : '—';
                $endLabel = $etime !== null ? date('Y-m-d H:i', $etime) : '—';
                $now = time();
                if ($stime !== null && $etime !== null && $now >= $stime && $now < $etime) {
                    $state = '*INUSE*';
                } elseif ($stime !== null && $etime !== null) {
                    $state = 'IDLE';
                } else {
                    $state = '—';
                }
            @endphp
            <tr>
                <td>{{ $h->tenant_pkey ?? $h->cluster ?? '—' }}</td>
                <td>{{ $startLabel }}</td>
                <td>{{ $endLabel }}</td>
                <td>{{ $h->description ?? '—' }}</td>
                <td>{{ $h->route ?? '—' }}</td>
                <td>{{ $state }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
