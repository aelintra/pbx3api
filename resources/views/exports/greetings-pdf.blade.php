<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Greetings report</title>
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
    <h1>Greetings</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($greetings) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Number</th>
                <th>Tenant</th>
                <th>Name</th>
                <th>Original filename</th>
                <th>File Type</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($greetings as $g)
            @php
                $raw = trim((string) ($g->pkey ?? ''));
                if (preg_match('/^usergreeting(\d+)$/i', $raw, $m)) {
                    $number = $m[1];
                } elseif ($raw !== '' && preg_match('/^\d+$/', $raw)) {
                    $number = $raw;
                } else {
                    $number = $raw !== '' ? $raw : '—';
                }
            @endphp
            <tr>
                <td>{{ $number }}</td>
                <td>{{ $g->tenant_pkey ?? $g->cluster ?? '—' }}</td>
                <td>{{ $g->cname ?? '—' }}</td>
                <td>{{ $g->filename ?? '—' }}</td>
                <td>{{ $g->type ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
