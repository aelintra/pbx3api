# Greetings (greeting) audit – sarkgreeting panel port

**Status: Done.** API (GreetingRecordController, greetingrecords routes, syscmd tenant dir) and SPA (Greetings list/create/detail) are implemented and committed.

**Purpose:** Define the Greeting model, controller, and validation for the sarkgreeting panel port. Greetings are **tenant-scoped** (table `greeting` in tenant SQL). Metadata lives in the **greeting** table; the actual **audio** is stored as files under the sounds directory, **per tenant**. The user uploads any `.wav`/`.mp3`; we store the **original upload name** in the `filename` column, but we save the file on disk as **`usergreeting{pkey}.{wav|mp3}`** under the tenant’s sounds folder. Same pattern as Queue, Agent, IVR, Conference: id/shortuid set on create, pkey unique per cluster, route binding by shortuid.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `greeting`).

**File storage:**
- **Old system:** All greetings were stored flat in `/usr/share/asterisk/sounds/` (e.g. `usergreeting40001.mp3`).
- **This system:** Greetings are stored **per tenant** in a subdirectory keyed by cluster shortuid. The **saved** file on disk is always named **`usergreeting{pkey}.{wav|mp3}`** (extension from the uploaded file).  
  **Path:** `/usr/share/asterisk/sounds/{cluster_shortuid}/usergreeting{pkey}.{wav|mp3}`  
  Example: `/usr/share/asterisk/sounds/D87Zy03c/usergreeting40001.mp3`  
  The API must create the cluster subdirectory if missing on upload. Download and delete use this path (cluster + pkey + type), not the `filename` column.

**Create flow:** Create is done by **uploading a file** from the user. The file can be **any** `.wav` or `.mp3` (any original name). The **original name** of the uploaded file is stored in the **filename** column (for display/reference). The file is **saved on disk** as **`usergreeting{pkey}.{wav|mp3}`** in `sounds/{cluster_shortuid}/`. So the client must supply **pkey** (e.g. 4-digit greeting number) and the **file**; the API stores the upload’s original name in `filename`, derives `type` from the file extension, and writes the file as `usergreeting{pkey}.{wav|mp3}`.

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.
- **pkey** is the greeting number; treat it as an **INTEGER** in API validation and the SPA. It is unique **per cluster**. Same pkey in different tenants is allowed. Required on create. (DB column is TEXT; store numeric value as string or cast on read, but enforce integer semantics at the API boundary.)
- **TENANT_SCOPED_PATTERN:** Store `cluster` as tenant **shortuid**; resolve client `cluster` (pkey/shortuid/id) via `cluster_identifier_to_shortuid`; set `id` and `shortuid` on create; update by `id`; `resolveRouteBinding` by shortuid then id then pkey.
- **filename** (column) = **original name** of the uploaded file (stored for display/reference). The file on disk is **saved** as `usergreeting{pkey}.{wav|mp3}`; download/delete use that saved name, not the `filename` column.

---

## All columns in `greeting` (from schema)

| Column     | In DB | Suggested role      | In updateableColumns | In model $fillable / $hidden | Notes |
|------------|-------|---------------------|----------------------|------------------------------|--------|
| id         | ✓     | Identity            | no                   | fillable (set on create)     | KSUID, PRIMARY KEY. Set on create only. |
| shortuid   | ✓     | Identity            | no                   | fillable (set on create)     | 8-char UID, UNIQUE. Set on create only. |
| pkey       | ✓     | Identity            | no (set on create)   | fillable (set on create)     | DB is TEXT but **treat as integer** in API/SPA; unique per cluster. |
| cname      | ✓     | Updateable          | yes                  | —                            | Common name. |
| filename   | ✓     | Set on create       | no (set from upload) | fillable (set on create)     | **Original name** of the uploaded file (any .wav or .mp3); stored for display/reference. The file on disk is saved as **usergreeting{pkey}.{wav|mp3}** under `sounds/{cluster_shortuid}/`. |
| cluster    | ✓     | Updateable          | yes (store shortuid) | —                            | Tenant; store cluster shortuid. |
| description| ✓     | Updateable          | yes                  | —                            | Short description. |
| type       | ✓     | Set on create/replace | yes (when replacing audio) | —                      | TEXT; derived from uploaded file extension (wav/mp3). |
| z_created  | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |
| z_updated  | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |
| z_updater  | ✓     | Display only        | no                   | guarded / not in fillable    | z_* never updateable. |

**Note:** API should enforce pkey (and optionally filename) unique per cluster in validator `after()` on create and update when pkey/filename changes.

---

## Gaps and mismatches

**Current API (GreetingController) does not use the greeting table.** It only:
- **index():** Lists filenames by reading `/usr/share/asterisk/sounds` (flat, regex `usergreeting*`), returns JSON array of file names. No DB. Does not use per-tenant subdirs.
- **download($greeting):** Serves file by name via `Storage::disk('greetings')` (root `/usr/share/asterisk/sounds`), no cluster subdir.
- **save():** Accepts file upload; validates name `usergreeting\d{4}\.(mp3|wav)`; moves file to sounds dir. No row inserted into `greeting`, no cluster subdir.
- **delete($greeting):** Deletes file from sounds dir. No row deleted from `greeting`.

So **metadata in the greeting table is currently unused by the API.** Also, the current implementation assumes a **flat sounds directory**, but the target layout is **per-tenant** `sounds/{cluster_shortuid}/`.

To align with the schema and tenant-scoped pattern we need either:

- **Option A:** Full CRUD on **greeting** table (model, index/show/save/update/delete by id/shortuid, tenant resolution, pkey unique per cluster) **and** file operations integrated into that same resource.
- **Option B:** Keep current file-only API for backward compatibility and **add** a separate or combined resource that does CRUD on the **greeting** table and links to the same files (e.g. list from DB with tenant, create row + upload file, edit row, delete row + file).

**Decision:** Option **B** (see Decisions applied). Keep the existing file-only `/greetings` endpoints for compatibility, and add a **new tenant-scoped greetings-metadata resource** backed by the `greeting` table (and using per-tenant file paths).

---

## Proposed implementation summary (after your decisions)

1. **Model:** `App\Models\Greeting`, `$table = 'greeting'`, `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`. **$fillable:** pkey, cname, filename, cluster, description, type (exclude id, shortuid, z_*). **$attributes** defaults: cluster default. **resolveRouteBinding:** by shortuid (exact, then case-insensitive), then id, then pkey.

2. **Controller(s):**
   - Keep existing **file-only** `GreetingController` and `/greetings` routes (compatibility). (May be marked deprecated later.)
   - Add a new **tenant-scoped** controller for the DB-backed resource (name TBD; e.g. `GreetingRecordController`) with CRUD on the `greeting` table and file operations:\n     **updateableColumns:** cname, cluster (exists:cluster,pkey), description. **pkey** is required integer on create and treated as identity-only after create. **filename** set from upload original name on create/replace. **type** derived from upload.\n     **index():** Return Greeting::orderBy('pkey')->get() (DB rows, sorted by pkey).\n     **save():** Require pkey (integer) + file upload; resolve cluster; enforce unique (pkey + cluster); set filename/type; write file to `sounds/{cluster_shortuid}/usergreeting{pkey}.{type}`.\n     **download():** Stream file from `sounds/{cluster_shortuid}/usergreeting{pkey}.{type}`.\n     **delete():** Delete row and the saved file.

3. **Routes (preference):** For the **DB-backed** resource, use:\n   - `GET /greetingrecords` (index)\n   - `POST /greetingrecords` (create row + upload)\n   - `GET /greetingrecords/{greetingrecord}` (show JSON)\n   - `PUT /greetingrecords/{greetingrecord}` (update metadata and/or replace audio)\n   - `DELETE /greetingrecords/{greetingrecord}` (delete row + file)\n   - `GET /greetingrecords/{greetingrecord}/download` (download file)\n\n   Keep the existing file-only `/greetings` endpoints unchanged for compatibility.

4. **SchemaService:** Add schema support for the **DB-backed** resource (e.g. `greetingrecords`), not the legacy file-only `/greetings` endpoints.

5. **SPA (PANEL_PATTERN):** Use the **DB-backed** resource (e.g. `greetingrecords`) for the Greetings panel.\n   List: pkey, shortuid, tenant, cname, original filename, type; Download, Edit, Delete.\n   Create: pkey (integer), tenant, cname/description, file upload (required).\n   Detail: edit metadata; optional replace audio.

6. **File storage:** File paths are **per tenant**: `/usr/share/asterisk/sounds/{cluster_shortuid}/usergreeting{pkey}.{wav|mp3}`. The controller builds this path from the row’s `cluster`, `pkey`, and `type` (not from the `filename` column). Create cluster subdir if missing on upload. Single Laravel disk root `/usr/share/asterisk/sounds`; read/write/delete using subdir `{cluster_shortuid}/` + `usergreeting{pkey}.{type}`.

---

## Your input needed

_(Answered; see Decisions applied.)_

---

## Decisions applied

1. **API shape:** **Option B** – keep existing file-only `/greetings` endpoints for compatibility; add a new tenant-scoped DB-backed resource for the `greeting` table (with file ops tied to rows).\n2. **Download route (new DB-backed resource):** Prefer `GET /greetingrecords/{greetingrecord}` = JSON show, and `GET /greetingrecords/{greetingrecord}/download` = file.\n3. **pkey type:** Treat **pkey as INTEGER** in API validation and SPA.\n4. **Index source:** `index()` returns rows from the **greeting** table, sorted by pkey, including tenant (cluster) information.
