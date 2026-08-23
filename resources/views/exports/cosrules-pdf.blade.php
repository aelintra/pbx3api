<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Class of Service report</title>
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
    <h1>Class of Service</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($cosrules) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Key</th>
                <th>Tenant</th>
                <th>Active</th>
                <th>Dialplan</th>
                <th>Default open</th>
                <th>Override open</th>
                <th>Default closed</th>
                <th>Override closed</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cosrules as $c)
            <tr>
                <td>{{ $c->cname ?? '—' }}</td>
                <td>{{ $c->pkey ?? '—' }}</td>
                <td>{{ $c->tenant_pkey ?? $c->cluster ?? '—' }}</td>
                <td>{{ $c->active ?? '—' }}</td>
                <td>{{ $c->dialplan ?? '—' }}</td>
                <td>{{ $c->defaultopen ?? '—' }}</td>
                <td>{{ $c->orideopen ?? '—' }}</td>
                <td>{{ $c->defaultclosed ?? '—' }}</td>
                <td>{{ $c->orideclosed ?? '—' }}</td>
                <td>{{ $c->description ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
