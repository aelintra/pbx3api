<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>IVRs report</title>
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
    <h1>IVRs</h1>
    <p class="meta">Generated {{ now()->format('Y-m-d H:i:s') }} — {{ count($ivrs) }} item(s)</p>
    <table>
        <thead>
            <tr>
                <th>IVR Direct Dial</th>
                <th>Local UID</th>
                <th>Tenant</th>
                <th>Description</th>
                <th>Greeting number</th>
                <th>Timeout</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ivrs as $ivr)
            <tr>
                <td>{{ $ivr->pkey ?? '—' }}</td>
                <td>{{ $ivr->shortuid ?? '—' }}</td>
                <td>{{ $ivr->tenant_pkey ?? $ivr->cluster ?? '—' }}</td>
                <td>{{ $ivr->description ?? '—' }}</td>
                <td>{{ $ivr->greetnum ?? '—' }}</td>
                <td>{{ $ivr->timeout ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
