<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tenants report</title>
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
    <h1>Tenants</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($tenants) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Pkey</th>
                <th>Local UID</th>
                <th>Description</th>
                <th>CLID</th>
                <th>Abstimeout</th>
                <th>Chanmax</th>
                <th>Timer</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tenants as $t)
            <tr>
                <td>{{ $t->pkey ?? '—' }}</td>
                <td>{{ $t->shortuid ?? '—' }}</td>
                <td>{{ $t->description ?? '—' }}</td>
                <td>{{ $t->clusterclid ?? '—' }}</td>
                <td>{{ $t->abstimeout ?? '—' }}</td>
                <td>{{ $t->chanmax ?? '—' }}</td>
                <td>{{ $t->masteroclo ?? 'AUTO' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
