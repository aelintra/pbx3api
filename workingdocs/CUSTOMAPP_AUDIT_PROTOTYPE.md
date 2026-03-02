# Custom App audit (Task 1.3)

**Purpose:** Align the CustomApp model, controller, and validation with the `appl` table (custom applications). Same process as Trunk, Queue, Agent, Route, IVR, and InboundRoute audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `appl`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `appl` (from schema)

| Column       | In DB | Suggested role | In updateableColumns | In model $guarded / $hidden | Notes |
|--------------|-------|----------------|----------------------|-----------------------------|--------|
| id           | ✓     | Identity       | no                   | —                           | KSUID, set on create. PRIMARY KEY in schema. |
| shortuid     | ✓     | Identity       | no                   | —                           | Set on create. UNIQUE. |
| pkey         | ✓     | Updateable     | yes                   | —                           | App name/key; unique per cluster (tenant). |
| active       | ✓     | Updateable     | yes                  | —                           | YES/NO. |
| cluster      | ✓     | Updateable     | yes                  | —                           | Tenant; store shortuid. |
| description  | ✓     | Updateable     | yes                  | —                           | |
| directdial   | ✓     | Updateable     | yes                  | —                           | INTEGER DEFAULT 0. |
| extcode      | ✓     | Updateable     | yes                  | —                           | |
| name         | ✓     | Deprecated     | no                   | guarded, hidden             | Schema: "deprecated, use cname instead". |
| cname        | ✓     | Updateable     | yes                  | —                           | Common name. |
| span         | ✓     | Updateable     | yes                  | —                           | Internal/External/Both/Neither. |
| striptags    | ✓     | Updateable     | yes                  | —                           | YES/NO. |
| z_created    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |
| z_updated    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |
| z_updater    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |

**Schema note:** `appl` has no explicit `UNIQUE("cluster", "pkey")` in the SQL shown; treat pkey as unique per cluster for consistency with other tenant resources.

---

## Gaps and mismatches

1. **Model uses $guarded and $primaryKey = 'pkey'**  
   Schema PRIMARY KEY is **id**. Other tenant models (Route, IVR, InboundRoute, etc.) use **id** as primaryKey so updates are by id (tenant-safe when pkey is renamed). **Recommend:** Change to `protected $primaryKey = 'id'` and add `resolveRouteBinding` by shortuid (like other panels). Replace **$guarded** with **$fillable** (whitelist of real appl columns). **name** is deprecated → keep in $hidden, not in $fillable (or in fillable but hidden from JSON).

2. **Controller: create mutates $updateableColumns**  
   `save()` does `$this->updateableColumns['pkey'] = 'required'` and `$this->updateableColumns['cluster'] = 'required|...'`, which mutates the shared array and can affect the next request (e.g. update). Use **local create rules** (e.g. `$createRules = array_merge($this->updateableColumns, [...])`) instead.

3. **Controller: duplicate check not tenant-scoped**  
   Create uses `$customapp->where('pkey','=',$request->pkey)->count()` — no cluster filter. Should check duplicate **(pkey, cluster)** within tenant; store cluster as shortuid like other controllers.

4. **Controller: duplicate error key**  
   Uses `'save'`; should use **'pkey'** for consistency.

5. **Controller: pkey not in updateableColumns**  
   If pkey is updateable (allow rename), add to updateableColumns and add pkey-uniqueness in `validator->after()` on update. If identity-only, leave as is.

6. **Controller: update by id**  
   Update uses `CustomApp::where('id', $id)->update($dirty)` when id is set, else falls back to pkey. With primaryKey = 'id', model binding will resolve by id/shortuid; ensure route uses shortuid so update is always by id.

7. **SPA**  
   CustomAppCreateView and CustomAppDetailView: ensure fields match schema (pkey, cluster, active, description, directdial, extcode, cname, span, striptags); pkey editable on detail only if audit says updateable; list/detail use shortuid for routing.

---

## Your input needed

1. **pkey** – Updateable (allow renaming the app key) or identity (never change after create)?
2. **cluster** – Keep in updateableColumns; document "not changeable in UI" if needed?
3. **name** – Confirm deprecated: not in updateableColumns, hidden from JSON, and in $fillable only if we still need to read it from DB (or omit from fillable and never mass-assign).
4. **span** – Confirm allowed values: Internal, External, Both, Neither (controller already has this).
5. **extcode** – Long text (Asterisk extensions.conf code); SPA uses a long textarea with hint.

---

## Decisions applied

1. **pkey** – Updateable; user can rename the app key; pkey-uniqueness per cluster on create and update.
2. **cluster** – Updateable from a dropdown of all clusters (tenant list); kept in updateableColumns.
3. **name** – Deprecated; not in updateableColumns; in $fillable for DB read/write; in $hidden from JSON.
4. **span** – Values: Internal, External, Both, Neither (confirmed).
5. **extcode** – Long text (Asterisk extensions.conf code); SPA: textarea 20 rows with hint "Asterisk extensions.conf dialplan code (long text)."

**Model:** `$primaryKey = 'id'`; `resolveRouteBinding` by shortuid then pkey; **$fillable** (pkey, active, cluster, description, directdial, extcode, name, cname, span, striptags); **$hidden** = ['name'].

**Controller:** Local `$createRules` for create (no mutation of updateableColumns); tenant-scoped duplicate check (cluster + pkey); duplicate error key **pkey**; pkey in updateableColumns; pkey-uniqueness in update when pkey sent and changed; `$request->input('cluster')`; update by id only.

**SPA:** Route and list/detail use **shortuid** (router param `:shortuid`, list links and delete by shortuid); detail: **pkey** editable and in save body; cluster dropdown (tenant list); extcode as textarea 20 rows with hint on create and detail.
