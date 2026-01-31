# pbx3api test folder

Manual API endpoint checks for the PBX3 API (GET endpoints against a live instance).

## Contents

| File | Purpose |
|------|---------|
| **fetch-all-endpoints.sh** | Script that calls every GET endpoint and prints OK/FAIL + status code. Uses `curl`; works with or without `jq` (uses Python to parse JSON for IDs when jq is missing). |
| **ENDPOINT_RESULTS.md** | Results from a run against https://192.168.1.205:44300/api: which endpoints returned 200, which failed and why. |
| **README.md** | This file. |

## Usage

```bash
./fetch-all-endpoints.sh [base_url] [bearer_token]
```

- **base_url** – API base (e.g. `https://192.168.1.205:44300/api`). Default: same.
- **bearer_token** – Sanctum Bearer token. Default: test token in script (change for other instances).

Requires: `curl`, and either `jq` or `python3` for extracting IDs when testing show-by-ID endpoints.
