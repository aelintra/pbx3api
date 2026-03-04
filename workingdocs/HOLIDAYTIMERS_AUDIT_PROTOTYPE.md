# Holiday Timers (holiday) audit – sarkholiday panel port

**Purpose:** Define the Holiday Timer (holiday) model, controller, and validation for the sarkholiday panel port. Holiday timers are **non-recurring** date ranges (e.g. “Christmas 25 Dec 00:00 – 26 Dec 00:00”) used for route overrides. They are **tenant-scoped** (table `holiday` in tenant SQL). API resource name: **holidaytimers**; table: **holiday**. Same pattern as other tenant resources: id/shortuid set on create, route binding by shortuid. Schema: **pkey** is TEXT (“not really used but satisfies tuple builder”); treat as system-generated on create (e.g. `sched` + random).

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `holiday`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.
- **pkey** is TEXT in schema; original sets `pkey = 'sched'.rand(100000,999999)`. Treat as **system-generated** on create; not user-editable.
- **TENANT_SCOPED_PATTERN:** Store `cluster` as tenant **shortuid**; resolve client `cluster` (pkey/shortuid/id) via `cluster_identifier_to_shortuid`; set `id` and `shortuid` on create; update by `id`; `resolveRouteBinding` by shortuid then id then pkey.
- **Times:** `stime` and `etime` are **INTEGER** (Unix epoch seconds). Original validates end > start and no overlap within same cluster.

---

## 0. Original sarkholiday panel (sail65)

**Source:** `sail65/sail-6/opt/sark/php/sarkholiday/view.php`, `update.php`, `delete.php`, `javascript.js`.

**List (showMain):**
- Table columns: **schedstart** (stime as d-m-Y H:i:s), **schedend** (etime as d-m-Y H:i:s), **cluster** (hidden on small/medium), **description** (shown as desc; hidden on small), **route** (hidden on small), **state** (IDLE or *INUSE* – computed: now between stime and etime → *INUSE*), Edit, Delete.
- Rows sorted by `stime`. Edit link uses `pkey`; delete uses `pkey`. Row id in markup is `pkey`.

**Create (showNew):**
- Only **description** (label `desc`) and **cluster** (tenant). Start/end date-time are **commented out** in the form – in `saveNew()` they default to today’s date and 00:00 for both start and end, then converted to epoch. pkey set to `'sched'.rand(100000, 999999)`.
- **Validation on save:** end > start; no overlap with existing rows in the same cluster (`? < etime AND stime < ?`).

**Edit (showEdit):**
- **description** (desc), **cluster** (dropdown), **route** (sysSelect – route override), **Start of period** (sdate datepicker + stime time), **End of period** (edate datepicker + etime time), hidden pkey.
- Date format in form: d-m-Y; time HH:mm (or H:i:s). Converted to epoch for stime/etime.
- **Validation on update:** end > start; no overlap in same cluster excluding current row.

**Inline edit (update.php):** Edits `desc` or `route`; route change also sets routeclass via helper. DataTables editable columns were commented out in javascript.js so inline edit is effectively disabled in the current script.

**Delete (delete.php):** Delete by id (from request); helper `delTuple('holiday', $id)`.

**Conclusion for port:** List columns: **Start** (stime), **End** (etime), **Cluster** (tenant), **Description**, **Route**, **State** (computed IDLE/*INUSE*), Edit, Delete. Create: description + cluster (start/end can default to same-day range or be required – see “Your input”). Detail/Edit: description, cluster, route, start date+time (→ stime), end date+time (→ etime). Validate end > start and no cluster overlap. **State** is display-only (computed).

---

## 1. Table: `holiday` (holidaytimers API)

### All columns in `holiday` (from schema)

| Column      | In DB | Suggested role      | In updateableColumns | Notes |
|-------------|-------|---------------------|----------------------|--------|
| id          | ✓     | Identity            | no                   | KSUID, PRIMARY KEY. Set on create only. |
| shortuid    | ✓     | Identity            | no                   | 8-char UID, UNIQUE. Set on create only. |
| pkey        | ✓     | Identity (system)   | no                   | TEXT; “not really used”. System-generated on create (e.g. sched + random). |
| cluster     | ✓     | Updateable          | yes (store shortuid) | Tenant; store cluster shortuid. |
| cname       | ✓     | Updateable          | yes                  | Common name. |
| description | ✓     | Updateable          | yes                  | Description; original UI labels it “desc”. |
| route       | ✓     | Updateable          | yes                  | Holiday scheduler route override. |
| stime       | ✓     | Updateable          | yes                  | INTEGER; Unix epoch start. |
| etime       | ✓     | Updateable          | yes                  | INTEGER; Unix epoch end. |
| z_created   | ✓     | Display only        | no                   | z_* never updateable. |
| z_updated   | ✓     | Display only        | no                   | z_* never updateable. |
| z_updater   | ✓     | Display only        | no                   | z_* never updateable. |

**Note:** There is no `state` column; state (IDLE / *INUSE*) is computed from current time and stime/etime.

---

## 2. Current API (gaps and mismatches)

### holidaytimers (HolidayTimerController, table `holiday`)

- **Model:** `HolidayTimer` has no `$primaryKey` (defaults to `id`), no `$keyType`, no `$fillable`, no `resolveRouteBinding`. Route param `{holidaytimer}` resolves by **id** only.
- **Controller:** **updateableColumns** use `'desc'` but table column is **`description`** – request key and DB column mismatch. **save():** Does not resolve cluster via `cluster_identifier_to_shortuid`; does not require cluster; sets pkey to `'sched'.rand(100000,999999)`. No validation that etime > stime; no overlap check within cluster. **update():** No cluster resolution; no update-by-id (dirty only) pattern; no overlap validation.
- **SchemaService:** holidaytimers is **not** in SchemaService; SPA cannot use useSchema('holidaytimers').
- **Gaps:** (1) Fix description vs desc (use `description` in updateableColumns or map); (2) Add cluster resolution on create/update; (3) Add resolveRouteBinding (shortuid → id → pkey); (4) Add getUpdateableColumns(); (5) Validate etime > stime and no cluster overlap on save/update; (6) Update by id (dirty only); (7) Register holidaytimers in SchemaService.

---

## 3. Proposed implementation summary

### 3.1 API

1. **Model:** `HolidayTimer`, table `holiday`. `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`. **$fillable:** cluster, cname, description, route, stime, etime (exclude id, shortuid, pkey, z_*). **resolveRouteBinding:** by shortuid (exact, then case-insensitive), then id, then pkey.

2. **Controller:** **updateableColumns:** cluster (exists:cluster,pkey), cname (string|nullable), description (string|nullable), route (string|nullable), stime (integer, epoch), etime (integer, epoch). **save():**
   - Resolve cluster with `cluster_identifier_to_shortuid($request->cluster)`; 422 if null.
   - Set id = generate_ksuid(), shortuid = generate_shortuid(), pkey = 'sched' . rand(100000, 999999).
   - Validate etime > stime (or allow same-second); validate no overlap in same cluster (optional but recommended – see original).
   - move_request_to_model; set cluster = shortuid; save().
   **update():** Same updateableColumns; cluster resolution if provided; validate etime > stime and no overlap (excluding current id); update by id (dirty only). **getUpdateableColumns()** for SchemaService.

3. **Routes:** Keep GET/POST holidaytimers, GET/PUT/DELETE holidaytimers/{holidaytimer}. Param resolves via resolveRouteBinding.

4. **SchemaService:** Add `'holidaytimers' => [HolidayTimerController::class, HolidayTimer::class]`.

### 3.2 SPA (PANEL_PATTERN) – aligned with original sarkholiday

- **List:** HolidayTimersListView – columns: **Start** (stime as formatted date-time), **End** (etime), **Cluster** (tenant pkey), **Description**, **Route**, **State** (computed: IDLE or *INUSE*), Edit, Delete. Sort by stime. Filter, Create.
- **Create:** HolidayTimerCreateView – **description** and **cluster** (tenant) only (matching original showNew); or include start/end date-time if product decision is to require them on create. useSchema('holidaytimers'), applySchemaDefaults.
- **Detail:** HolidayTimerDetailView – **description**, **cluster**, **route** (select – options from GET /routes, see below), **start** (date + time → stime epoch), **end** (date + time → etime epoch). id/shortuid/pkey read-only; state computed and read-only. useSchema for isReadOnly. Validate end > start; optional overlap warning or API validation.
- **Route select:** Same as original – **route** is a **dropdown**, not free text. Options from **GET /routes** (route pkeys). SPA loads routes and builds options (filter by cluster if desired; include "None" or empty for no override). Same pattern as InboundRouteCreateView/InboundRouteDetailView.
- **Router:** holidaytimers, holidaytimers/new, holidaytimers/:shortuid. **Nav:** “Holiday timers” or “Holidays”.

### 3.3 Validation (stime, etime, overlap)

- **stime / etime:** Integer (Unix epoch). Validation: etime >= stime (or strict > if desired).
- **Overlap (same cluster):** For save: no row where `cluster = X AND (stime, etime) overlaps (new_stime, new_etime)` (i.e. `new_stime < etime AND new_etime > stime`). For update: same check excluding current id.

---

## 4. Your input needed

1. **Create form:** Original create only has description + cluster; start/end default to today 00:00–00:00 in code. Should the port (a) match that (minimal create), or (b) require start/end on create?

2. **Overlap:** Enforce “no overlap in same cluster” in API (422) or only in SPA/warning?

3. **Route field:** **Decision:** Route is a **select** (same as original). Options from **GET /routes** (route pkeys). SPA: load routes, build dropdown; include "None" or empty for no override.

4. **List columns:** Confirm or adjust: Start, End, Cluster, Description, Route, State, Edit, Delete.

5. **Nav label:** “Holiday timers” or “Holidays”?

---

## 5. Decisions applied

_(To be filled after your review.)_
