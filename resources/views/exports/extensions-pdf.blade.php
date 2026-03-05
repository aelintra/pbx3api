<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Extensions report</title>
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
    <h1>Extensions</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($extensions) }} extension(s)</p>
    <table>
        <thead>
            <tr>
                <th>Ext</th>
                <th>SIP Identity</th>
                <th>Tenant</th>
                <th>User</th>
                <th>Type</th>
                <th>Device</th>
                <th>MAC</th>
                <th>Transport</th>
                <th>Active</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($extensions as $e)
            <tr>
                <td>{{ $e->pkey ?? '—' }}</td>
                <td>{{ $e->shortuid ?? '—' }}</td>
                <td>{{ $e->tenant_pkey ?? $e->cluster ?? '—' }}</td>
                <td>{{ trim($e->desc ?? $e->cname ?? $e->description ?? '') ?: '—' }}</td>
                <td>{{ $e->extension_type ?? '—' }}</td>
                <td>{{ $e->device ?? $e->technology ?? '—' }}</td>
                <td>{{ $e->macaddr ?? 'N/A' }}</td>
                <td>{{ $e->transport ?? '—' }}</td>
                <td>{{ $e->active ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
