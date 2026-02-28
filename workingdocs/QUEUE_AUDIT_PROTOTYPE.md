# Queue audit (Task 1.3)

**Purpose:** Align the Queue model, controller, and validation with the `queue` table. Same process as Trunk and Extension audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `queue`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `queue` (from schema)

| Column       | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|-------------|-------|------------------|----------------------|-----------------------------|--------|
| id          | ✓     | Identity         | no                   | guarded                    | KSUID, set on create. Not updateable. |
| shortuid    | ✓     | Identity         | no                   | —                          | Set on create. Not updateable. |
| pkey        | ✓     | Updateable       | save only            | —                          | Queue name/number; unique per cluster. |
| active      | ✓     | Updateable       | yes                  | —                          | YES/NO. |
| alertinfo   | ✓     | Updateable       | yes                  | —                          | |
| cluster     | ✓     | Updateable       | yes                  | —                          | Tenant; store shortuid. |
| cname       | ✓     | Updateable?      | no                   | guarded                    | Common name. Schema has it; model guards it. |
| description | ✓     | Updateable       | yes                  | —                          | |
| devicerec   | ✓     | Updateable       | yes                  | —                          | None/OTR/OTRR/Inbound/default. |
| divert      | ✓     | Updateable       | yes                  | —                          | INTEGER. |
| greetnum    | ✓     | Updateable       | yes                  | —                          | DEFAULT 'None'. |
| greeting    | ✓     | Updateable       | yes                  | —                          | Will replace greetnum (comment). |
| name        | ✓     | Display / deprecated | no                 | guarded, hidden            | Deprecated; use cname. |
| members     | ✓     | Updateable       | yes                  | —                          | |
| options     | ✓     | Updateable       | yes                  | —                          | DEFAULT 'CiIknrtT'. |
| musicclass  | ✓     | Updateable       | yes                  | —                          | |
| retry       | ✓     | Updateable       | yes                  | —                          | INTEGER DEFAULT 1. |
| wrapuptime  | ✓     | Updateable       | yes                  | —                          | INTEGER DEFAULT 0. |
| maxlen      | ✓     | Updateable       | yes                  | —                          | INTEGER DEFAULT 0. |
| outcome     | ✓     | Display / fixed  | no                   | guarded                    | DEFAULT 'None'. |
| strategy    | ✓     | Updateable       | yes                  | —                          | ringall, roundrobin, etc. |
| timeout     | ✓     | Updateable       | yes                  | — (hidden)                 | INTEGER DEFAULT 30. Model hides it. |
| z_created   | ✓     | Display only     | no                   | guarded                    | z_* never updateable. |
| z_updated   | ✓     | Display only     | no                   | guarded                    | z_* never updateable. |
| z_updater   | ✓     | Display only     | no                   | guarded                    | z_* never updateable. |

---

## Gaps and mismatches

1. **Queue model uses $guarded (not $fillable)**  
   Per pattern, **MUST** use **$fillable** (whitelist). Replace `$guarded` with a `$fillable` list of all mass-assignable columns (exclude id, shortuid, z_*, and any you confirm as display-only: e.g. outcome, name, cname if not updateable).

2. **QueueController::save() does not set id and shortuid**  
   Before `$queue->save()`, the controller never sets `$queue->id` or `$queue->shortuid`. The table has `id` PRIMARY KEY and `shortuid` UNIQUE with no DB default, so create will fail (null id). **Fix:** Set `$queue->id = generate_ksuid();` and `$queue->shortuid = generate_shortuid();` after `move_request_to_model` and before `save()`, same as TrunkController and ExtensionController.

3. **Model $attributes**  
   `'options' => 't'` in the model; schema default is `'CiIknrtT'`. Consider aligning with schema or documenting.

4. **Model $hidden**  
   `timeout` is hidden; schema has timeout as a normal column. If timeout is updateable, it should not be hidden (or hide only if UI never shows it).

5. **No Form Request**  
   Queue uses Request + Validator only for both create and update; no QueueRequest. No change needed for Task 2.

6. **devicerec**  
   Controller has `in:None,OTR,OTRR,Inbound,default`. Schema default is `'None'`. Extension uses `default,None,Inbound,Outbound,Both`. Confirm allowed values for queue.

7. **pkey uniqueness on update**  
   Update does not validate pkey uniqueness when client sends a different pkey. If the UI allows renaming (pkey change), add a `validator->after()` like Trunk/Extension to check uniqueness per cluster when pkey is present and changed.

---

## Your input needed

1. **pkey** – Updateable (allow queue name/number rename) or identity (never change)?
2. **cluster** – Keep in updateableColumns; document “not changeable in UI” if needed, or remove from updateable?
3. **cname** – Keep display-only (guarded) or make updateable (add to controller and remove from guarded)?
4. **name** – Confirm display-only / deprecated (keep guarded and hidden).
5. **outcome** – Confirm display-only / system-managed (keep guarded).
6. **timeout** – Should it be visible in API responses (remove from $hidden) or stay hidden?
7. **strategy** – Controller allows `ringall,roundrobin,leastrecent,fewestcalls,random,rrmemory`. Confirm or extend from Asterisk docs.
8. Any column you want **removed** from updateableColumns or **added** (e.g. cname if updateable).

Once you’ve decided, we’ll:
1. Fix **QueueController::save()** to set id and shortuid before save().
2. Replace **Queue model** `$guarded` with **$fillable** and set `$hidden` per your choices.
3. Optionally add pkey-uniqueness check in update() when pkey is present and changed.
