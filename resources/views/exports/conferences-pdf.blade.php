<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Conferences report</title>
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
    <h1>Conferences</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($conferences) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Room</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Name</th>
                <th>Type</th>
                <th>Active</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($conferences as $c)
            <tr>
                <td>{{ $c->pkey ?? '—' }}</td>
                <td>{{ $c->shortuid ?? '—' }}</td>
                <td>{{ $c->tenant_pkey ?? $c->cluster ?? '—' }}</td>
                <td>{{ $c->cname ?? '—' }}</td>
                <td>{{ $c->type ?? '—' }}</td>
                <td>{{ $c->active ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
