<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Route profiles report</title>
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
    <h1>Route profiles</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($profiles) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Name</th>
                <th>Modes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($profiles as $p)
            <tr>
                <td>{{ $p->tenant_pkey ?? $p->cluster ?? '—' }}</td>
                <td>{{ $p->name ?? '—' }}</td>
                <td>{{ $p->modes_count ?? 0 }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
