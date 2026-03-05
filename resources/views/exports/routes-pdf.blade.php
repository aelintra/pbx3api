<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Routes report</title>
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
    <h1>Routes (outbound)</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($routes) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Description</th>
                <th>Dialplan</th>
                <th>Path 1</th>
                <th>Active</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($routes as $r)
            <tr>
                <td>{{ $r->pkey ?? '—' }}</td>
                <td>{{ $r->shortuid ?? '—' }}</td>
                <td>{{ $r->tenant_pkey ?? $r->cluster ?? '—' }}</td>
                <td>{{ trim($r->description ?? '') ?: '—' }}</td>
                <td>{{ trim($r->dialplan ?? '') ?: '—' }}</td>
                <td>{{ trim($r->path1 ?? '') ?: '—' }}</td>
                <td>{{ $r->active ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
