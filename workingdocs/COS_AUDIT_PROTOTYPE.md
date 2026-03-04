# Class of Service (cos) audit – sarkcos panel port

**Purpose:** Define the Class of Service (CoS) model, controller, and validation for the sarkcos panel port, and how cosrules relate to cosopens/coscloses. CoS is **tenant-scoped** (table `cos` in tenant SQL). API resources: **cosrules** (table `cos`), **cosopens** (table `ipphonecosopen`), **coscloses** (table `ipphonecosclosed`). Same pattern as Queue, Agent, IVR: id/shortuid set on create, pkey unique per cluster and **not updateable** (identity-only), route binding by shortuid for cosrules. The **cosopens** and **coscloses** tables are junction tables linking extensions (ipphone) to CoS rules (open/closed state per extension).

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (tables `cos`, `ipphonecosopen`, `ipphonecosclosed`).

**Rules applied (after your decisions):**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable (for `cos` only; junction tables use composite PK).
- **pkey** (CoS key/name) is unique **per cluster**; same pkey in different tenants is allowed. Required on create and **not updateable** after create (identity-only). (Schema has no UNIQUE(cluster,pkey); API should enforce in validator on create.)
- **TENANT_SCOPED_PATTERN (cos only):** Store `cluster` as tenant **shortuid**; resolve client `cluster` (pkey/shortuid/id) via `cluster_identifier_to_shortuid`; set `id` and `shortuid` on create; update by `id`; `resolveRouteBinding` by shortuid then id then pkey.
- **Junction tables (cosopens, coscloses):** Composite PRIMARY KEY (cluster, ipphone_pkey, cos_pkey). Used to assign which CoS rules are “open” or “closed” per extension. No id/shortuid; identify rows by (cluster, ipphone_pkey, cos_pkey) or a composite string for routes.

---

## 1. Table: `cos` (cosrules API)

### All columns in `cos` (from schema)

| Column         | In DB | Suggested role      | In updateableColumns | In model $fillable / $hidden | Notes |
|----------------|-------|---------------------|----------------------|------------------------------|--------|
| id             | ✓     | Identity            | no                   | fillable (set on create)     | KSUID, PRIMARY KEY. Set on create only. |
| shortuid       | ✓     | Identity            | no                   | fillable (set on create)     | 8-char UID, UNIQUE. Set on create only. |
| pkey           | ✓     | Identity-only       | no                   | fillable (set on create)     | TEXT; CoS key/name. Unique per cluster. Required on create; not updateable. |
| active         | ✓     | Updateable          | yes                  | —                            | TEXT DEFAULT 'YES'; YES/NO. |
| cluster        | ✓     | Updateable          | yes (store shortuid) | —                            | Tenant; store cluster shortuid. |
| cname          | ✓     | Updateable          | yes                  | —                            | Common name. |
| defaultclosed  | ✓     | Display/system      | no                   | —                            | TEXT DEFAULT 'NO'; YES/NO. System-driven; not user-editable in this pass. |
| defaultopen    | ✓     | Display/system      | no                   | —                            | TEXT DEFAULT 'NO'; YES/NO. System-driven; not user-editable in this pass. |
| description    | ✓     | Updateable          | yes                  | —                            | Short description. |
| dialplan       | ✓     | Updateable (required) | yes                | —                            | TEXT DEFAULT NULL; **required** in API; dialplan fragment. |
| orideclosed    | ✓     | Display/system      | no                   | —                            | TEXT DEFAULT 'NO'; YES/NO. **Not updateable** by user. |
| orideopen      | ✓     | Display/system      | no                   | —                            | TEXT DEFAULT 'NO'; YES/NO. **Not updateable** by user. |
| z_created      | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |
| z_updated      | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |
| z_updater      | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |

**Note:** Schema has no `UNIQUE("cluster", "pkey")`; product intent is pkey unique per tenant. API will enforce duplicate (pkey + cluster) in validator `after()` on create and update (when pkey changes).

---

## 2. Table: `ipphonecosopen` (cosopens API)

Links an extension (ipphone) to a CoS rule in “open” state. Composite PRIMARY KEY (cluster, ipphone_pkey, cos_pkey).

| Column        | In DB | Role        | Notes |
|---------------|-------|-------------|--------|
| id            | ✓     | (optional)  | TEXT; not part of PK. |
| cluster       | ✓     | Tenant      | Part of PK. |
| active        | ✓     | Updateable  | TEXT DEFAULT 'YES'. |
| ipphone_pkey  | ✓     | FK → ipphone | Part of PK. |
| cos_pkey      | ✓     | FK → cos    | Part of PK. |
| z_created etc.| ✓     | Display     | z_* not updateable. |

---

## 3. Table: `ipphonecosclosed` (coscloses API)

Links an extension (ipphone) to a CoS rule in “closed” state. Composite PRIMARY KEY (cluster, ipphone_pkey, cos_pkey).

| Column        | In DB | Role        | Notes |
|---------------|-------|-------------|--------|
| id            | ✓     | (optional)  | TEXT; not part of PK. |
| cluster       | ✓     | Tenant      | Part of PK. |
| active        | ✓     | Updateable  | TEXT DEFAULT 'YES'. |
| ipphone_pkey  | ✓     | FK → ipphone | Part of PK. |
| cos_pkey      | ✓     | FK → cos    | Part of PK. |
| z_*           | ✓     | Display     | Not updateable. |

---

## 4. Current API (gaps and mismatches)

### cosrules (ClassOfServiceController, table `cos`)

- **Model:** `ClassOfService` uses `$primaryKey = 'pkey'` and has **no** `cluster` in fillable/updateableColumns. Schema has `id` as PRIMARY KEY and `shortuid` UNIQUE; model does not use id/shortuid for routing.
- **Controller:** No `cluster` on save/update; duplicate check is global on `pkey` only (not per cluster). `dialplan` is required on save; schema has DEFAULT NULL. No `resolveRouteBinding` (route param is pkey). No cluster resolution via `cluster_identifier_to_shortuid`.
- **Gaps:** (1) Cos is tenant-scoped in schema but API does not set or validate cluster; (2) primary key for Laravel should align with schema (id) and use shortuid for route binding for consistency with other tenant resources; (3) pkey uniqueness should be per cluster; (4) dialplan required vs schema DEFAULT NULL is a product choice to confirm.

### cosopens (CosOpenController, table `ipphonecosopen`)

- **Model:** `CosOpen` uses `$primaryKey = 'ipphone_pkey'`; table has composite PK (cluster, ipphone_pkey, cos_pkey). Route binding for show/update/delete therefore cannot uniquely resolve a row from a single segment.
- **Controller:** save() does not set cluster; duplicate check is on (ipphone_pkey, cos_pkey) only. ExtensionController creates default cos open/closed rows when creating extensions (create_default_cos_instances).
- **Gaps:** (1) No cluster in create/update; (2) show/update/delete need a composite identifier (e.g. encode cluster+ipphone_pkey+cos_pkey, or use a surrogate id if added).

### coscloses (CosCloseController, table `ipphonecosclosed`)

- **Model:** `CosClose` uses `$primaryKey = 'ipphone_pkey'`; same composite PK issue as cosopens.
- **Controller:** delete() has wrong type hint: `CosOpen $cosclose` should be `CosClose $cosclose`. Same cluster/composite-ID gaps as cosopens.

---

## 5. Proposed implementation summary

### 5.1 cosrules (main CoS panel – align with TENANT_SCOPED_PATTERN)

1. **Model:** `App\Models\ClassOfService` (keep name; table `cos`). Use `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`. Add **$fillable:** pkey, active, cluster, cname, description, dialplan (exclude id, shortuid, z_*, defaultopen/defaultclosed/orideopen/orideclosed). **$attributes** defaults: active YES, cluster default, defaultclosed NO, defaultopen NO, orideclosed NO, orideopen NO. **resolveRouteBinding:** by shortuid (exact, then case-insensitive), then id, then pkey.

2. **Controller:** `ClassOfServiceController`. **updateableColumns (cosrules):** active (in:YES,NO), cluster (exists:cluster,pkey), cname, description, `dialplan` (**required** string). `pkey` and `oride*` are **not** in updateableColumns (identity/system-only). **save():**
   - Resolve cluster with `cluster_identifier_to_shortuid($request->cluster)`; 422 if null.
   - Validate pkey required (alpha_dash) and dialplan required; in `after()` check unique (pkey + cluster).
   - move_request_to_model; set cluster = shortuid; set id = generate_ksuid(), shortuid = generate_shortuid(); save().
   **update():** Same updateableColumns (no pkey or oride*); update by id (dirty only). **delete():** By model binding.

3. **Routes:** Keep GET/POST cosrules, GET/PUT/DELETE cosrules/{classofservice}. Under auth:sanctum, abilities:admin. Route param resolves via resolveRouteBinding (shortuid or id or pkey).

4. **SchemaService:** Add `'cosrules' => [ClassOfServiceController::class, ClassOfService::class]` for GET /schemas (if not already present).

5. **SPA (PANEL_PATTERN):** Three panels for **cosrules** only (main CoS list): ClassOfServiceListView, ClassOfServiceCreateView, ClassOfServiceDetailView. **List columns (exact):** CoS key (pkey), Tenant, Name (cname), Active, Default open, Default closed, Description, Edit, Delete. **Create:** pkey (CoS key, required), tenant (cluster, required), cname, description, active, dialplan (required). **Detail:** same fields; id/shortuid read-only; pkey read-only; Save/Cancel/Delete. useSchema('cosrules'); tenant resolution. **Route path:** keep `cosrules` (matches API): `cosrules`, `cosrules/new`, `cosrules/:shortuid`. **Nav label:** “Class of Service”.

### 5.2 cosopens / coscloses (junction tables – later)

- **Current behaviour:** `ipphonecosopen` and `ipphonecosclosed` rows are generated from **Extensions** panel rules (ExtensionController::create_default_cos_instances). They are not edited directly from a CoS panel.
- **Plan:** Leave cosopens/coscloses API as-is for now. SPA will **not** create dedicated cosopens/coscloses panels in this pass. Any UI to adjust open/closed CoS per extension can be added later on the Extensions detail screen, reusing the existing APIs.

---

## 6. Your input needed

1. **cosrules pkey** – **Resolved:** identity-only after create; not updateable.

2. **dialplan** – **Resolved:** required on create/update (cannot be null/empty).

3. **cosopens / coscloses** – **Resolved:** Defer SPA and deeper API refactor; they are generated from Extensions panel rules and can be handled later.

4. **SPA naming** – **Resolved:** Route path will be `cosrules` (matching the API), with nav label “Class of Service”.

5. **List display** – **Resolved:** List columns: pkey, Tenant, cname, active, defaultopen, defaultclosed, description, Edit, Delete.

---

## 7. Decisions applied

1. **pkey** – Identity-only after create; not updateable. Uniqueness enforced per cluster at create time.
2. **dialplan** – Required field in the API and SPA (cannot be null/empty).
3. **cosopens/coscloses** – Left as-is for now; rows are generated from Extensions panel rules. No SPA panels in this pass.
4. **SPA route/name** – Use `cosrules` for the route path (cosrules, cosrules/new, cosrules/:shortuid) and “Class of Service” as the nav label.
5. **SPA list columns** – CoS key (pkey), Tenant, Name (cname), Active, Default open, Default closed, Description, Edit, Delete.
