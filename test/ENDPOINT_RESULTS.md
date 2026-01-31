# PBX3 API GET endpoint test results

Run against live PBX3 test system: **https://192.168.1.205:44300/api** (Bearer token, admin).

---

## Succeeded (200 OK)

### Auth
- `GET auth/whoami`
- `GET auth/users`

### Index (list) endpoints
- `GET agents`
- `GET astamis`
- `GET backups`
- `GET backups/new`
- `GET coscloses`
- `GET cosopens`
- `GET cosrules`
- `GET customapps`
- `GET daytimers`
- `GET destinations`
- `GET extensions`
- `GET firewalls/ipv4`
- `GET greetings`
- `GET holidaytimers`
- `GET inboundroutes`
- `GET ivrs`
- `GET logs`
- `GET queues`
- `GET snapshots`
- `GET snapshots/new`
- `GET routes`
- `GET syscommands`
- `GET syscommands/commit`
- `GET syscommands/reboot`
- `GET syscommands/pbxrunstate`
- `GET syscommands/stop`
- `GET sysglobals`
- `GET tenants`
- `GET trunks`

### Show-by-ID endpoints
- `GET tenants/affcot`
- `GET extensions/1000`
- `GET cosrules/prohibited`
- `GET customapps/77742`
- `GET inboundroutes/01244459296`
- `GET ivrs/50770`
- `GET queues/1060`
- `GET routes/AFFCOT_INTERSITE`
- `GET trunks/PDH4S03`

---

## Failed – environment / setup (not API bugs)

| Endpoint | Status | Reason |
|----------|--------|--------|
| `GET astamis/CoreSettings` | 500 | Asterisk Manager not reachable: `fsockopen(): Unable to connect to 127.0.0.1:5038 (Connection refused)` |
| `GET astamis/CoreStatus` | 500 | Same |
| `GET astamis/Reload` | 500 | Same |
| `GET astamis/Status` | 500 | Same |
| `GET astamis/ExtensionState/1000` | 500 | Same |
| `GET astamis/MailboxCount/1000` | 500 | Same |
| `GET astamis/MailboxStatus/1000` | 500 | Same |
| `GET astamis/QueueStatus/1060` | 500 | Same |
| `GET astamis/QueueSummary/1060` | 500 | Same |
| `GET astamis/IAXpeers` | 500 | Same |
| `GET astamis/CoreShowChannels` | 500 | Same |
| `GET astamis/DeviceStateList` | 500 | Same |
| `GET astamis/ExtensionStateList` | 500 | Same |
| `GET astamis/VoicemailUsersList` | 500 | Same |
| `GET extensions/1000/runtime` | 500 | Same (runtime from Asterisk) |
| `GET firewalls/ipv6` | 500 | `/etc/shorewall6/pbx3_rules6` missing (IPv6 firewall not configured) |
| `GET syscommands/start` | 503 | `"PBX already running"` (expected when PBX is up) |

---

## Failed – likely API/route bugs

| Endpoint | Status | Reason |
|----------|--------|--------|
| `GET logs/cdrs10` | 500 | `Undefined variable $limit` in `LogController.php` line 45. Route param `limit` not passed to controller. |
| `GET astamis/SIPpeers` | 404 | `"AMI Action invalid or unsupported"` – action name/case may not match controller. |

---

## Failed – script used wrong ID (show not verified)

| Endpoint | Status | Reason |
|----------|--------|--------|
| `GET daytimers/dateSeg466938` | 404 | Script took wrong field as ID; not a valid daytimer key. |
| `GET holidaytimers/36` | 404 | First item’s `id` may not be route key, or record doesn’t exist. |

---

## How to re-run

From **pbx3api/test/**:

```bash
chmod +x fetch-all-endpoints.sh
./fetch-all-endpoints.sh [base_url] [bearer_token]
```

Defaults: `base_url=https://192.168.1.205:44300/api`, token as in script (override for other instances).
