<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Queues report</title>
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
    <h1>Queues / Ring groups</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($queues) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Queue</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Name</th>
                <th>Active</th>
                <th>Strategy</th>
                <th>Timeout</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($queues as $q)
            <tr>
                <td>{{ $q->pkey ?? '—' }}</td>
                <td>{{ $q->shortuid ?? '—' }}</td>
                <td>{{ $q->tenant_pkey ?? $q->cluster ?? '—' }}</td>
                <td>{{ $q->cname ?? $q->name ?? '—' }}</td>
                <td>{{ $q->active ?? '—' }}</td>
                <td>{{ $q->strategy ?? '—' }}</td>
                <td>{{ $q->timeout !== null && $q->timeout !== '' ? $q->timeout : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
