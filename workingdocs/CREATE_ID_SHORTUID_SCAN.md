# Create endpoints: id and shortuid scan

**Purpose:** List every create/save endpoint that writes to a table with `id` and `shortuid` columns, and whether it sets them before persist.

**Rule (TENANT_SCOPED_PATTERN):** For tables with `id` (KSUID) and `shortuid`, the API **must** set `$model->id = generate_ksuid()` and `$model->shortuid = generate_shortuid()` (or equivalent) before `save()` / `create()`. The client must not send them.

**Schema source:** `sqlite_create_tenant.sql` and `sqlite_create_instance.sql`. Tables with `"id" TEXT PRIMARY KEY` and `"shortuid" TEXT UNIQUE` require both to be set on create.

---

## Tables that have id + shortuid

From schema:

- **Tenant:** `cluster` (id, shortuid)
- **Instance:** `trunks` (id, shortuid)
- **Tenant:** `ipphone` (extensions), `queue`, `agent`, `route`, `ivrmenu`, `inroutes`, `appl` (customapps), `cos`, `holiday`, `dateseg`

Tables that do **not** have id/shortuid (pkey-only PK): `tt_help_core`, `device`.  
Junction-style: `ipphonecosopen`, `ipphonecosclosed` have `id` but composite PRIMARY KEY; create may not require id/shortuid depending on usage.

---

## Scan result: create endpoints that require id and shortuid

| Controller | Method | Table | Sets id? | Sets shortuid? | Status |
|------------|--------|--------|----------|----------------|--------|
| **TrunkController** | save() | trunks | ✓ | ✓ | OK |
| **ExtensionController** | save() | ipphone | ✓ (in attrs) | ✓ (in attrs) | OK |
| **ExtensionController** | mailbox() | ipphone | ✓ | ✓ | OK (fixed) |
| **ExtensionController** | unprovisioned() | ipphone | ✓ | ✓ | OK (fixed) |
| **ExtensionController** | webrtc() | ipphone | ✓ | ✓ | OK (fixed) |
| **ExtensionController** | provisioned() | ipphone | ✓ | ✓ | OK (fixed) |
| **CustomAppController** | save() | appl | ✓ | ✓ | OK |
| **InboundRouteController** | save() | inroutes | ✓ | ✓ | OK |
| **IvrController** | save() | ivrmenu | ✓ | ✓ | OK |
| **TenantController** | save() | cluster | ✓ | ✓ | OK |
| **QueueController** | save() | queue | ✓ | ✓ | OK (fixed) |
| **AgentController** | save() | agent | ✓ | ✓ | OK (fixed) |
| **RouteController** | save() | route | ✓ | ✓ | OK (fixed) |
| **ClassOfServiceController** | save() | cos | ✓ | ✓ | OK (fixed) |
| **HolidayTimerController** | save() | holiday | ✓ | ✓ | OK (fixed) |
| **DayTimerController** | save() | dateseg | ✓ | ✓ | OK (fixed) |

---

## Endpoints that do not require id/shortuid

| Controller | Table | Reason |
|------------|--------|--------|
| HelpCoreController | tt_help_core | PK is `pkey` only; no id/shortuid in schema. |
| DeviceController | device | PK is `pkey` only; no id/shortuid in schema. |
| CosOpenController | ipphonecosopen | Composite PK (cluster, ipphone_pkey, cos_pkey); id is optional. |
| CosCloseController | ipphonecosclosed | Composite PK; id optional. |

---

## Summary

- **All create endpoints that require id/shortuid now set them (16 total).** Fixed: QueueController, AgentController, RouteController, ClassOfServiceController, HolidayTimerController, DayTimerController, and ExtensionController::mailbox(), unprovisioned(), webrtc(), provisioned().
