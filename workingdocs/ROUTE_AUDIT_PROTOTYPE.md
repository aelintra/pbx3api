# Route audit (Task 1.3)

**Purpose:** Align the Route model, controller, and validation with the `route` table (outbound routes). Same process as Trunk, Queue, and Agent audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `route`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `route` (from schema)

| Column    | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|-----------|-------|------------------|----------------------|-----------------------------|--------|
| id        | ✓     | Identity         | no                   | —                           | KSUID, set on create. Not updateable. |
| shortuid  | ✓     | Identity         | no                   | —                           | Set on create. Not updateable. |
| pkey      | ✓     | Updateable       | no                   | —                           | Route name/dialplan key; unique per cluster. |
| active    | ✓     | Updateable       | yes                  | —                           | YES/NO. |
| alternate | ✓     | Updateable?      | yes                  | guarded, hidden             | Alternate dial for desk-to-desk shortdial. |
| auth      | ✓     | Updateable?      | yes                  | guarded, hidden             | YES/NO; used for PIN dial. |
| cluster   | ✓     | Updateable       | yes                  | —                           | Tenant; store shortuid. |
| cname     | ✓     | Updateable       | yes                  | —                           | Common name. |
| description | ✓   | Updateable       | yes                  | —                           | |
| dialplan  | ✓     | Updateable       | yes                  | —                           | Route dialplan (e.g. _XXXXXX). |
| path1     | ✓     | Updateable       | yes                  | —                           | DEFAULT 'None'; exists:trunks,pkey. |
| path2     | ✓     | Updateable       | yes                  | —                           | |
| path3     | ✓     | Updateable       | yes                  | —                           | |
| path4     | ✓     | Updateable       | yes                  | —                           | |
| route     | ✓     | Display / deprecated | yes                | —                           | Schema: "always the same as pkey. Not used, not needed." |
| strategy  | ✓     | Updateable       | yes                  | —                           | hunt or balance. |
| z_created | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updated | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updater | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |

---

## Decisions applied

1. **Model:** Replaced **$guarded** with **$fillable** (pkey, active, alternate, auth, cluster, cname, description, dialplan, path1–path4, route, strategy). **$hidden** = `route` (deprecated column). alternate and auth are updateable.
2. **Controller:** **pkey** added to updateableColumns (nullable|string for update). **route** column removed from updateableColumns (deprecated). Create duplicate error uses **pkey** key. **update()** has `validator->after()` for pkey uniqueness when pkey is present and changed. save() already uses local createRules and normalizePathInputs.
3. **SPA:** Create uses **description** (not desc); schema defaults include description, cname. Detail has editable **pkey**, **cname**, **description**; save body includes pkey, cname, description; route name editable unless schema read_only.

---

## Gaps and mismatches (resolved)

1. **Route model uses $guarded (not $fillable)**  
   Per pattern, **MUST** use **$fillable** (whitelist). Replace `$guarded` with a `$fillable` list. Currently guarded: alternate, auth, z_*; hidden: alternate, auth. Controller nevertheless has alternate and auth in updateableColumns, so model blocks mass assignment for those.

2. **RouteController::save() mutates instance updateableColumns**  
   Save adds `pkey` and overwrites `cluster` on `$this->updateableColumns`, so the controller state is changed for the next request. Should use a local rules array for create (same pattern as Queue/Agent).

3. **pkey not in updateableColumns**  
   Update path has no pkey; create adds it only for that request. If pkey is updateable (allow rename), add to updateableColumns with nullable rule and add pkey-uniqueness in `validator->after()` on update.

4. **Update has no pkey-uniqueness check**  
   If the UI allows renaming (pkey change), add `validator->after()` to check uniqueness per cluster when pkey is present and changed (like Queue/Agent).

5. **Column "route"**  
   Schema comment: "always the same as pkey. Not used, not needed." Controller currently has `'route' => 'string|nullable'` in updateableColumns. Consider removing from updateable (and optionally hide in model) so it’s not written by API.

6. **SPA RouteCreateView uses "desc"**  
   The `route` table has **description** and **dialplan**, not **desc**. Create view has a `desc` ref — likely should be **description** (or dialplan is separate). Confirm and align SPA with schema column names.

7. **RouteDetailView**  
   Uses editDesc; same as above — ensure edit fields map to schema (description, dialplan), not desc.

---

## Your input needed

1. **pkey** – Updateable (allow route name/dialplan key rename) or identity (never change)?
2. **cluster** – Keep in updateableColumns; document "not changeable in UI" if needed, or remove from updateable?
3. **alternate** – Keep updateable (and remove from model $guarded/$hidden) or display-only / system-managed?
4. **auth** – Keep updateable (PIN dial; remove from guarded/hidden) or display-only?
5. **route** (column) – Remove from updateableColumns and treat as display-only/deprecated (schema says not used, not needed)?
6. **path1–path4** – Confirm validation `exists:trunks,pkey` is correct (trunks table is instance-scoped; pkey is the trunk identifier). Allow null/None when no trunk selected?
7. **dialplan** – Any format constraint (e.g. Asterisk pattern _XXXXXX) to enforce in validation or leave as string nullable?
8. Any column you want **removed** from updateableColumns or **added**.

**Implemented** per decisions above. Path1–4 normalization (None → null, only when key sent) was done earlier; full audit (model, controller, SPA) is now complete.
