<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Trunks report</title>
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
    <h1>Trunks</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($trunks) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Description</th>
                <th>Active</th>
                <th>Host</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trunks as $tr)
            <tr>
                <td>{{ $tr->pkey ?? '—' }}</td>
                <td>{{ $tr->shortuid ?? '—' }}</td>
                <td>{{ $tr->tenant_pkey ?? $tr->cluster ?? '—' }}</td>
                <td>{{ $tr->description ?? '—' }}</td>
                <td>{{ $tr->active ?? '—' }}</td>
                <td>{{ $tr->host ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
