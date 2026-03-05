<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Agents report</title>
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
    <h1>Agents</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($agents) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Agent</th>
                <th>Tenant</th>
                <th>Name</th>
                <th>Q1</th>
                <th>Q2</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($agents as $a)
            <tr>
                <td>{{ $a->pkey ?? '—' }}</td>
                <td>{{ $a->tenant_pkey ?? $a->cluster ?? '—' }}</td>
                <td>{{ $a->cname ?? $a->name ?? '—' }}</td>
                <td>{{ trim($a->queue1 ?? '') ?: '—' }}</td>
                <td>{{ trim($a->queue2 ?? '') ?: '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
