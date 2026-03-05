<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inbound routes report</title>
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
    <h1>Inbound routes</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($inboundroutes) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>DiD/CLiD</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Name</th>
                <th>Open</th>
                <th>Closed</th>
                <th>Type</th>
                <th>Active</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($inboundroutes as $r)
            <tr>
                <td>{{ $r->pkey ?? '—' }}</td>
                <td>{{ $r->shortuid ?? '—' }}</td>
                <td>{{ $r->tenant_pkey ?? $r->cluster ?? '—' }}</td>
                <td>{{ $r->trunkname ?? '—' }}</td>
                <td>{{ $r->openroute ?? '—' }}</td>
                <td>{{ $r->closeroute ?? '—' }}</td>
                <td>{{ $r->technology ?? '—' }}</td>
                <td>{{ $r->active ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
