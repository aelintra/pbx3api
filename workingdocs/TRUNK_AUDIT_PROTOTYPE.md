# Trunk audit (prototype for Task 1)

**Purpose:** Decide which columns on `trunks` are updateable vs display-only, so we can fix the controller and drop TrunkRequest. Your choices here will be the template for other resources.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql` (table `trunks`).

**Rules applied so far:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable** (you confirmed).
- **Identity columns** set by the system on create: `id`, `shortuid` → treated as **not updateable**, displayable.

---

## All columns in `trunks` (from schema)

| Column       | In DB | Suggested role | Current updateableColumns | Notes |
|-------------|-------|----------------|---------------------------|--------|
| id          | ✓     | Identity      | no                        | KSUID, set on create. Not updateable. |
| shortuid    | ✓     | Identity      | no                        | Set on create. Not updateable. |
| pkey        | ✓     | Updateable | no                        | Display name; unique per cluster. Currently excluded from update. Rename allowed? |
| active      | ✓     | Updateable    | yes                       | YES/NO. |
| alertinfo   | ✓     | Updateable    | yes                       | |
| callback    | ✓     | Updateable | no                        | In schema; not in current updateableColumns. |
| callerid    | ✓     | Updateable    | yes                       | |
| callprogress| ✓     | Updateable    | yes                       | |
| closeroute  | ✓     | Updateable | no                        | In schema; not in current updateableColumns. |
| cluster     | ✓     | Updateable | yes                       | Tenant; TRUNK_ROUTE_MULTITENANCY says not changeable for now. |
| cname       | ✓     | Updateable    | yes                       | |
| description | ✓     | Updateable    | yes                       | |
| devicerec   | ✓     | Updateable    | yes                       | |
| disa        | ✓     | Updateable    | yes                       | |
| disapass    | ✓     | Updateable    | yes                       | |
| host        | ✓     | Updateable    | yes                       | |
| iaxreg      | ✓     | Updateable    | yes                       | |
| inprefix    | ✓     | Updateable    | yes                       | |
| match       | ✓     | Updateable    | yes                       | |
| moh         | ✓     | Updateable    | yes                       | |
| openroute   | ✓     | Updateable | no                        | In schema; not in current updateableColumns. |
| password    | ✓     | Updateable    | yes                       | |
| peername    | ✓     | Updateable    | yes                       | |
| pjsipreg    | ✓     | Updateable    | yes                       | |
| privileged  | ✓     | Updateable | no                        | In schema; not in current updateableColumns. |
| register    | ✓     | Updateable    | yes                       | |
| swoclip     | ✓     | Updateable    | yes                       | |
| tag         | ✓     | Updateable    | yes                       | |
| technology  | ✓     | Updateable | yes                       | SIP or IAX2; user supplies via dropdown on create and update. No Carrier table. |
| transform   | ✓     | Updateable    | yes                       | |
| transport   | ✓     | Updateable    | yes                       | |
| trunkname   | ✓     | Updateable    | yes                       | Should default to pkey if not given or empty|
| username    | ✓     | Updateable    | yes                       | |
| z_created   | ✓     | Display only  | no                        | z_* never updateable (rule). |
| z_updated   | ✓     | Display only  | no                        | z_* never updateable (rule). |
| z_updater   | ✓     | Display only  | no                        | z_* never updateable (rule). |

**Technology:** User supplies **technology** (SIP or IAX2) via the SPA dropdown on create and update. The API accepts `technology` and stores it in the `technology` column. There is no Carrier table; the API does not query or use `carrier`, `sipiaxpeer`, or `sipiaxuser`.

---

## Applied (from your Suggested role choices)

- **TrunkController::$updateableColumns** — Updated to include all columns you marked Updateable: added `pkey`, `callback`, `closeroute`, `openroute`, `privileged`, `technology` with validation rules.
- **Trunk model** — Removed `callback`, `closeroute`, `openroute`, `privileged`, `technology` from `$guarded` and `$hidden` so they are updateable and returned in API responses.
- **TrunkController::update()** — Now uses `Request` + single `Validator` only (TrunkRequest no longer used). Pkey uniqueness checked in `validator->after()` when client sends a different pkey.
- **TrunkRequest** — No longer used for update; file remains for reference.
- **TrunkController::save()** — Technology from user only: create uses local `$createRules` (does not mutate `$updateableColumns`), requires `technology` (SIP or IAX2), sets `$trunk->technology` from request. No Carrier table; no `carrier`/`sipiaxpeer`/`sipiaxuser`; duplicate error key is `pkey`.

---

## Your input (completed)

Original questions (now resolved from your Suggested role):

1. **pkey** – Updateable (allow rename) or identity (never change)?
2. **cluster** – Keep in updateableColumns but document “not changeable in UI for now” / force default, or remove from updateable and set only server-side?
3. **callback, closeroute, openroute, privileged** – Add to updateableColumns, or leave as display-only / system-managed?
4. **technology** – User-supplied (SIP or IAX2) on create and update; no carrier/Carrier table.

And confirm or correct:
- Any column you want **removed** from updateableColumns (e.g. cluster if we never let the client change it).
- Any column you want **added** to updateableColumns (e.g. callback, closeroute, openroute, privileged if they should be editable).

Once you’ve marked these, we’ll:
1. Set `updateableColumns` in TrunkController to exactly that set (with validation rules).
2. Proceed to drop TrunkRequest and use Request + Validator only (Task 2 for Trunk).
