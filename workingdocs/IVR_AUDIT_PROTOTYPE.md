# IVR audit (Task 1.3)

**Purpose:** Align the Ivr model, controller, and validation with the `ivrmenu` table. Same process as Trunk, Queue, Agent, and Route audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `ivrmenu`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `ivrmenu` (from schema)

| Column        | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|---------------|-------|------------------|----------------------|-----------------------------|--------|
| id            | ✓     | Identity         | no                   | —                           | KSUID, set on create. Not updateable. |
| shortuid      | ✓     | Identity         | no                   | —                           | Set on create. Not updateable. |
| pkey          | ✓     | Updateable       | no                   | —                           | IVR name/number; 3–5 digits; unique per cluster. |
| active        | ✓     | Updateable       | yes                  | —                           | YES/NO. |
| alert0–alert11| ✓     | Updateable       | yes                  | —                           | Alertinfo per keypress. |
| cluster       | ✓     | Updateable       | yes                  | —                           | Tenant; store shortuid. |
| cname         | ✓     | Updateable       | yes                  | —                           | Common name. |
| description   | ✓     | Updateable       | yes                  | —                           | DEFAULT 'None'. |
| greetnum      | ✓     | Updateable       | yes                  | —                           | Greeting number; DEFAULT 'None'. |
| listenforext  | ✓     | Updateable       | yes                  | —                           | YES/NO. |
| name          | ✓     | Display / deprecated | yes                | —                           | Schema: deprecated, use cname. |
| option0–option11 | ✓  | Updateable       | yes                  | —                           | Routed name per keypress; DEFAULT 'None'. |
| tag0–tag11    | ✓     | Updateable       | yes                  | —                           | Alphatag per keypress. |
| timeout       | ✓     | Updateable       | yes                  | —                           | DEFAULT '30'; timeout name. |
| z_created     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updated     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updater     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |

---

## Decisions applied

1. **Model:** Replaced **$guarded** with **$fillable** (all ivrmenu columns except id, shortuid, z_*). **$hidden** = `name` (deprecated). Simplified $attributes; timeout default '30' per schema.
2. **Controller:** **pkey** added to updateableColumns (nullable, 3-5 digits regex). **name** removed from updateableColumns. save() uses local createRules; duplicate error on **pkey** key; custom message. update() has validator->after() for pkey uniqueness when pkey present and changed; uses $request->input('cluster').
3. **SPA:** Create and detail use description, cname (no name). Detail: editable **pkey** (IVR Direct Dial), validateIvrPkey before save; name field removed. Create: name field removed; schema defaults without name.

---

## Gaps and mismatches (resolved)

1. **Ivr model uses $guarded (not $fillable)**  
   Per pattern, **MUST** use **$fillable** (whitelist). Replace `$guarded` with a `$fillable` list. Currently only z_* are guarded; all other columns would be in fillable.

2. **IvrController::save() mutates instance updateableColumns**  
   Save sets `$this->updateableColumns['pkey']` and `['cluster']`, so the controller state changes for the next request. Use a local rules array for create (same pattern as Queue/Agent/Route).

3. **pkey not in updateableColumns for update**  
   Update path has no pkey. If pkey is updateable (allow IVR name/number rename), add to updateableColumns with nullable rule and add pkey-uniqueness in `validator->after()` on update.

4. **Update has no pkey-uniqueness check**  
   If the UI allows renaming (pkey change), add `validator->after()` to check uniqueness per cluster when pkey is present and changed.

5. **Create duplicate error uses 'save' key**  
   Should use **pkey** and message like "That IVR number is already in use in this tenant." (same pattern as Route/Queue).

6. **name** – Schema marks as deprecated (use cname). Keep in updateableColumns or remove and hide in model?

7. **update()** – Uses `$request->cluster`; should use `$request->input('cluster')` for consistency. Empty `validator->after()` can be removed or replaced with pkey-uniqueness logic.

8. **SPA** – Ensure IVR create and detail views use schema column names (description, cname, not desc); all updateable fields present; pkey editable only if audit confirms.

---

## Your input needed

1. **pkey** – Updateable (allow IVR name/number rename; 3–5 digits) or identity (never change)?
2. **cluster** – Keep in updateableColumns; document "not changeable in UI" if needed?
3. **name** – Remove from updateableColumns and treat as display-only/deprecated (hide in model), or keep updateable for legacy?
4. **timeout** – Schema DEFAULT '30'; confirm validation (string nullable or specific allowed values)?
5. **greetnum** – Confirm allowed values (e.g. 'None' or greeting identifiers); null/empty → 'None'?
6. **option0–option11** – Confirm validation (string nullable; destination names); normalise empty to 'None' or allow null?
7. Any column you want **removed** from updateableColumns or **added**.

**Implemented** per decisions above.
