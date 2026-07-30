# Fleet-first tenant create (node API)

**Spec:** `pbx3/workingdocs/FLEET_TENANT_CREATE_REQUIREMENTS.md`  
**Branch:** merged to **`main`** (2026-07-30)

## Endpoints

| Method | Path | Auth | Role |
|--------|------|------|------|
| `POST` | `/api/fleet/tenants` | `fleet.token` | Gatekeeper provision — create cluster on this node |
| `POST` | `/api/tenants` | Sanctum admin | **Solo only** — rejected with **403** when `FleetPostureService::isFleetNode()` |
| `DELETE` | `/api/tenants/{tenant}` | Sanctum | **Solo only** — rejected with **403** on fleet nodes |

Fleet wipe during a move still uses `DELETE /api/fleet/tenants/{shortuid}` (mobility), not Sanctum Delete.

## Body (`POST /fleet/tenants`)

Same as Sanctum create: required `pkey`, `description`; optional CLID / local area digit strings and other cluster create fields. Server assigns `shortuid` + `fqdn` from `globals.domain`.

## Fleet posture

`PBX3_FLEET_MODE=true` or active Egress trunk → fleet node. Solo (kick-tyres) Create/Delete unchanged.
