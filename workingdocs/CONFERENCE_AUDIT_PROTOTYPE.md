# Conference (meetme) audit – sarkconference panel port

**Status: Done.** API (Conference model, ConferenceController, conferences routes) and SPA (Conferences list/create/detail) are implemented.

**Purpose:** Define the Conference (meetme) model, controller, and validation for the sarkconference panel port. Conferences are **tenant-scoped** (table `meetme` in tenant SQL). API resource name: **conferences**; table: **meetme**. Same pattern as Queue, Agent, IVR, etc.: id/shortuid set on create, pkey unique per cluster, route binding by shortuid.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `meetme`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.
- **pkey** (room number) is unique **per cluster**; same pkey in different tenants is allowed. Required on create; updateable only if product allows room-number change (audit assumes updateable with uniqueness check per cluster).
- **TENANT_SCOPED_PATTERN:** Store `cluster` as tenant **shortuid**; resolve client `cluster` (pkey/shortuid/id) via `cluster_identifier_to_shortuid`; set `id` and `shortuid` on create; update by `id`; `resolveRouteBinding` by shortuid then id then pkey.

---

## All columns in `meetme` (from schema)

| Column     | In DB | Suggested role     | In updateableColumns | In model $fillable / $hidden | Notes |
|------------|-------|--------------------|----------------------|------------------------------|--------|
| id         | ✓     | Identity           | no                   | fillable (set on create)     | KSUID, PRIMARY KEY. Set on create only. |
| shortuid   | ✓     | Identity           | no                   | fillable (set on create)       | 8-char UID, UNIQUE. Set on create only. |
| pkey       | ✓     | Identity/updateable| yes                  | —                             | INTEGER; room number. Unique per cluster. |
| active     | ✓     | Updateable         | yes                  | —                             | TEXT DEFAULT 'YES'; YES/NO. |
| cluster    | ✓     | Updateable         | yes (store shortuid)  | —                             | Tenant; store cluster shortuid. |
| cname      | ✓     | Updateable         | yes                  | —                             | Common name. |
| adminpin   | ✓     | Updateable         | yes                  | —                             | TEXT DEFAULT 'None'; PIN for admin. |
| description| ✓     | Updateable         | yes                  | —                             | Short description. |
| pin        | ✓     | Updateable         | yes                  | —                             | TEXT DEFAULT 'None'; participant PIN. |
| type       | ✓     | Updateable         | yes                  | —                             | TEXT DEFAULT 'simple'; e.g. simple, hosted. |
| z_created  | ✓     | Display only       | no                   | guarded / not in fillable     | z_* never updateable. |
| z_updated  | ✓     | Display only       | no                   | guarded / not in fillable     | z_* never updateable. |
| z_updater  | ✓     | Display only       | no                   | guarded / not in fillable     | z_* never updateable. |

**Note:** Schema has no `UNIQUE("cluster", "pkey")` in the snippet; product intent is pkey unique per tenant. API will enforce duplicate (pkey + cluster) in validator `after()` on create and update (when pkey changes).

---

## Gaps and mismatches

**New resource.** There is no existing Conference model or ConferenceController in pbx3api. This audit defines the intended implementation. No gaps to fix; implementation will follow this audit and TENANT_SCOPED_PATTERN + PANEL_PATTERN.

---

## Proposed implementation summary

1. **Model:** `App\Models\Conference`, `$table = 'meetme'`, `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`. **$fillable:** pkey, active, cluster, cname, adminpin, description, pin, type (exclude id, shortuid, z_*). **$attributes** defaults: active YES, cluster default, adminpin None, pin None, type simple. **resolveRouteBinding:** by shortuid (exact, then case-insensitive), then id, then pkey.

2. **Controller:** `ConferenceController`. **updateableColumns:** pkey (nullable|integer), active (in:YES,NO), cluster (exists:cluster,pkey), cname, adminpin, description, pin, type (in:simple,hosted). **save():**
   - Resolve cluster with `cluster_identifier_to_shortuid($request->cluster)`; 422 if null.
   - Validate pkey required|integer; in `after()` check unique (pkey + cluster).
   - move_request_to_model; set cluster = shortuid; set id = generate_ksuid(), shortuid = generate_shortuid(); save().
   **update():** Same updateableColumns; in `after()` if pkey changed, check unique (pkey + cluster) excluding current id; update by id (dirty only). **delete():** By model binding.

3. **Routes:** GET/POST /conferences, GET/PUT/DELETE /conferences/{conference}. Under auth:sanctum, abilities:admin.

4. **SchemaService:** Add `'conferences' => [ConferenceController::class, Conference::class]` for GET /schemas.

5. **SPA (PANEL_PATTERN):** Three panels: ConferencesListView, ConferenceCreateView, ConferenceDetailView. List: Room (pkey), Local UID, Tenant, Name, Type, Active, Edit, Delete. Create: Identity (pkey room number, cname, description); Settings (tenant, active, type, pin, adminpin). Detail: same fields, id/shortuid read-only; Save/Cancel/Delete top and bottom. useSchema('conferences'); tenant resolution (cluster → pkey for dropdowns). Router: conferences, conferences/new, conferences/:shortuid. Nav: Conferences link after Queues.

6. **Validation (API):** pkey integer (required on create); optional pin/adminpin (string or integer per schema TEXT – schema says TEXT DEFAULT 'None', so accept string or integer; if integer, cast when saving). type in:simple,hosted.

---

## Your input needed

1. **pkey** – Confirm **updateable** on edit (room number can be changed) with uniqueness per cluster enforced in validator `after()`. Or should pkey be **identity-only** after create (no change of room number)?

2. **pin / adminpin** – Schema type is TEXT DEFAULT 'None'. Should API accept **integer** (e.g. numeric PIN) or **string** only? (SPA can send number; API can validate integer|nullable and store as string in DB.)

3. **type** – Schema DEFAULT 'simple'. Restrict to `simple` and `hosted` only (sail65 had these), or allow other values?

4. **SPA defaults** – Any schema defaults to document for create form (e.g. active YES, type simple, pin/adminpin None)?

5. **List display** – Any extra column desired (e.g. description) or keep to Room, Tenant, Name, Type, Active only?

---

## Decisions applied

_(To be filled after your review.)_
