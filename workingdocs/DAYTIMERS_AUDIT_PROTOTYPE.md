# Day Timers (dateseg) audit – sarktimer panel port

**Purpose:** Define the Day Timer (dateseg) model, controller, and validation for the sarktimer panel port. Day timers are **recurring** time rules (e.g. “weekdays 09:00–17:00”) used for open/closed behaviour. They are **tenant-scoped** (table `dateseg` in tenant SQL). API resource name: **daytimers**; table: **dateseg**. Same pattern as other tenant resources: id/shortuid set on create, route binding by shortuid. Schema note: **pkey** is INTEGER UNIQUE (schema comment: “candidate to be removed”); treat as system-generated identity.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `dateseg`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.
- **pkey** is INTEGER UNIQUE in schema (one unique integer per row); schema comment says “candidate to be removed”. Treat as **system-generated** on create (e.g. unique integer); not user-editable. Current API sets `pkey = 'dateSeg'.rand(100000,999999)` (string) which may not match INTEGER type.
- **TENANT_SCOPED_PATTERN:** Store `cluster` as tenant **shortuid**; resolve client `cluster` (pkey/shortuid/id) via `cluster_identifier_to_shortuid`; set `id` and `shortuid` on create; update by `id`; `resolveRouteBinding` by shortuid then id then pkey.
- **Recurring fields:** datemonth, dayofweek, month, timespan define when the rule is active (cron-like). state is typically IDLE (display/system).

---

## 0. Original sarktimer panel (sail65)

**Source:** `sail65/sail-6/opt/sark/php/sarktimer/view.php`, `update.php`, `javascript.js`.

**List (showMain):**
- Table columns: **cluster** (hidden on small/medium), **sclose** (start time – first part of timespan, or *), **eclose** (end time – second part of timespan, or *), **weekday** (dayofweek; hidden on small), **description** (shown as desc; hidden on small), **state**, Edit, Delete.
- **datemonth** and **month** are commented out in the list – not displayed; they stay at default `*`.
- **timespan** is stored as one value (`*` or `HH:MM-HH:MM`) but displayed as two columns (sclose, eclose). Rows sorted by cluster, dayofweek.
- Edit link uses `pkey`; delete uses `pkey`. Row id in markup is `pkey`.

**Create (showNew):**
- Only **description** (label `desc`) and **cluster** (tenant). No timespan, dayofweek, etc. – those get DB defaults (`*`). pkey set to `'dateSeg'.rand(100000,999999)`.

**Edit (showEdit):**
- **allday** (YES/NO) – when YES, timespan is set to `*-*` and start/end time inputs are hidden.
- **cluster** (dropdown).
- **sclose** (sdate) and **eclose** (edate) – time pickers (HH:mm); combined into `timespan` as `sdate-edate` (or `*-*` when allday).
- **dayofweek** – select: Every Day (*), mon, tue, wed, thu, fri, sat, sun.
- **description** (as desc).
- pkey in hidden input (used for update).

**Inline edit (update.php):** Edits desc, beginclose, endclose (which map to description and timespan). dayofweek, datemonth, month not in the inline-editable columns in the DataTables config (those columns are commented out in javascript.js).

**Conclusion for port:** List columns should match original: Cluster, Start (sclose), End (eclose), Day of week, Description, State, Edit, Delete. Create: description + cluster. Detail/Edit: allday, cluster, start time, end time (or single timespan), dayofweek, description. **month** and **datemonth** can remain optional/advanced (not in original UI) or hidden; **timespan** is required but shown in UI as Start/End.

---

## 1. Table: `dateseg` (daytimers API)

### All columns in `dateseg` (from schema)

| Column     | In DB | Suggested role      | In updateableColumns | Notes |
|------------|-------|---------------------|----------------------|--------|
| id         | ✓     | Identity            | no                   | KSUID, PRIMARY KEY. Set on create only. |
| shortuid   | ✓     | Identity            | no                   | 8-char UID, UNIQUE. Set on create only. |
| pkey       | ✓     | Identity (system)   | no                   | INTEGER UNIQUE; schema “candidate to be removed”. System-generated on create. |
| active     | ✓     | Updateable         | yes                  | TEXT DEFAULT 'YES'; YES/NO. |
| cluster    | ✓     | Updateable         | yes (store shortuid) | Tenant; store cluster shortuid. |
| cname      | ✓     | Updateable         | yes                  | Common name. |
| datemonth  | ✓     | Updateable         | yes                  | TEXT DEFAULT '*'; day-of-month: * or 1–31. **Not in original sarktimer UI** (commented out); optional in port. |
| dayofweek  | ✓     | Updateable         | yes                  | TEXT DEFAULT '*'; * or mon,tue,wed,thu,fri,sat,sun. |
| description| ✓     | Updateable         | yes                  | TEXT DEFAULT '*NEW RULE*'. In sail65 shown as desc. |
| month      | ✓     | Updateable         | yes                  | TEXT DEFAULT '*'; * or jan..dec. **Not in original sarktimer UI** (commented out); optional in port. |
| state      | ✓     | Display/system     | no                   | TEXT DEFAULT 'IDLE'; not user-editable. |
| timespan   | ✓     | Updateable         | yes                  | TEXT DEFAULT '*'; * or HH:MM-HH:MM. **Original UI shows as two fields:** Start (sclose) and End (eclose). |
| z_created  | ✓     | Display only       | no                   | z_* never updateable. |
| z_updated  | ✓     | Display only       | no                   | z_* never updateable. |
| z_updater  | ✓     | Display only       | no                   | z_* never updateable. |

---

## 2. Current API (gaps and mismatches)

### daytimers (DayTimerController, table `dateseg`)

- **Model:** `DayTimer` has no `$primaryKey` (defaults to `id`), no `$fillable` (relies on guarded only), no `resolveRouteBinding`. Route param `{daytimer}` therefore resolves by **id** (KSUID) only; SPA would need to use long id in URLs unless we add shortuid binding.
- **Controller:** **updateableColumns** include cluster, datemonth, dayofweek, description, month, timespan (regex). **Missing from updateableColumns:** active, cname. **save():** Does not resolve cluster via `cluster_identifier_to_shortuid`; does not require cluster; sets pkey to string `'dateSeg'.rand()` (schema has pkey INTEGER UNIQUE – may need integer). No uniqueness check for pkey (pkey is globally unique in schema).
- **SchemaService:** daytimers is **not** in SchemaService; SPA cannot use useSchema('daytimers') for defaults/read_only until added.
- **Gaps:** (1) Add cluster resolution on create; (2) pkey: use integer or keep string (clarify with schema/backend); (3) Add resolveRouteBinding (shortuid → id → pkey) for friendlier SPA URLs; (4) Add active, cname to updateableColumns; (5) Add getUpdateableColumns() and register daytimers in SchemaService; (6) Update by id (dirty only) like other tenant controllers.

---

## 3. Proposed implementation summary

### 3.1 API

1. **Model:** `DayTimer`, table `dateseg`. Keep `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`. Add **$fillable:** active, cluster, cname, datemonth, dayofweek, description, month, timespan (exclude id, shortuid, pkey, state, z_*). **$attributes:** active YES, cluster default, datemonth/dayofweek/month/timespan '*', state IDLE. **resolveRouteBinding:** by shortuid (exact, then case-insensitive), then id, then pkey.

2. **Controller:** **updateableColumns:** active (in:YES,NO), cluster (exists:cluster,pkey), cname, datemonth (in:*,1..31), dayofweek (in:*,mon,tue,wed,thu,fri,sat,sun), description (string), month (in:*,jan..dec), timespan (regex `*` or HH:MM-HH:MM). **save():**
   - Resolve cluster with `cluster_identifier_to_shortuid($request->cluster)`; 422 if null.
   - Set id = generate_ksuid(), shortuid = generate_shortuid(), pkey = unique integer (e.g. time-based or next available – see “Your input” below).
   - move_request_to_model for updateable columns; set cluster = shortuid; save().
   **update():** Same updateableColumns; if cluster changes, set cluster from resolution; update by id (dirty only). **delete():** By model binding. **getUpdateableColumns()** for SchemaService.

3. **Routes:** Keep GET/POST daytimers, GET/PUT/DELETE daytimers/{daytimer}. Param resolves via resolveRouteBinding.

4. **SchemaService:** Add `'daytimers' => [DayTimerController::class, DayTimer::class]`.

### 3.2 SPA (PANEL_PATTERN) – aligned with original sarktimer

- **List:** DayTimersListView – columns (match sail65): **Cluster** (tenant), **Start** (sclose – first part of timespan or *), **End** (eclose – second part of timespan or *), **Day of week**, **Description**, **State**, Edit, Delete. Filter, sort, Create. Optionally add Active, cname if desired; month/datemonth were not in original list.
- **Create:** DayTimerCreateView – **description** and **cluster** (tenant) only, matching original showNew. Other fields get schema defaults (*). useSchema('daytimers'), applySchemaDefaults.
- **Detail:** DayTimerDetailView – **allday** (YES/NO); when NO, show **Start** and **End** time inputs (combined into timespan as HH:MM-HH:MM); **cluster**, **dayofweek** (Every Day / mon..sun), **description**. Optionally expose month, datemonth for advanced use. id/shortuid/pkey/state read-only. useSchema for isReadOnly.
- **Router:** daytimers, daytimers/new, daytimers/:shortuid. **Nav:** “Day timers” or “Recurring timers”.

### 3.3 Validation (timespan, dayofweek, month, datemonth)

- **timespan:** `*` or regex `(2[0-3]|[01][0-9]):([0-5][0-9])-(2[0-3]|[01][0-9]):([0-5][0-9])` (HH:MM-HH:MM).
- **dayofweek:** in:*,mon,tue,wed,thu,fri,sat,sun (lowercase per existing controller).
- **month:** in:*,jan,feb,mar,apr,may,jun,jul,aug,sep,oct,nov,dec.
- **datemonth:** in:*,1,2,...,31.

---

## 4. Your input needed

1. **pkey:** Schema is INTEGER UNIQUE. Current code sets a string. Prefer (a) **system-generated integer** (e.g. next max+1, or time-based unique), or (b) keep string and relax schema if acceptable? If (a), we need a reliable way to generate a unique integer per row on create.

2. **state:** Leave as display-only (IDLE) or ever updateable from API/SPA?

3. **List columns:** Confirm or adjust: cname, Tenant, Active, Description, Timespan, Day of week, Month, Date month, Edit, Delete. Any reorder or renames (e.g. “Time span” vs “Timespan”)?

4. **Nav label:** “Day timers” or “Recurring timers” or “sarktimer”?

---

## 5. Decisions applied

_(To be filled after your review.)_
