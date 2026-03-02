# Inbound Route audit (Task 1.3)

**Purpose:** Align the InboundRoute model, controller, and validation with the `inroutes` table (DDI/CLID inbound routes). Same process as Trunk, Queue, Agent, Route, and IVR audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `inroutes`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `inroutes` (from schema)

| Column       | In DB | Suggested role | In updateableColumns | In model $guarded / $hidden | Notes |
|--------------|-------|----------------|----------------------|-----------------------------|--------|
| id           | ✓     | Identity       | no                   | —                           | KSUID, set on create. |
| shortuid     | ✓     | Identity       | no                   | —                           | Set on create. |
| pkey         | ✓     | Updateable     | no                   | —                           | Inbound number/CLID/mask (Asterisk extension format). Unique per cluster. |
| active       | ✓     | Updateable     | yes                  | —                           | YES/NO. |
| alertinfo    | ✓     | Updateable     | yes                  | —                           | |
| callback     | ✓     | Updateable     | yes                  | guarded, hidden             | Callback trunk. |
| callerid     | ✓     | Updateable     | yes                  | hidden                      | |
| callprogress | ✓     | Updateable     | yes                  | hidden                      | YES/NO. |
| closeroute   | ✓     | Updateable     | yes                  | guarded, hidden             | Closed inbound route; default 'None'. |
| cluster      | ✓     | Updateable     | yes                  | —                           | Tenant; store shortuid. |
| cname        | ✓     | Updateable     | yes                  | —                           | |
| description  | ✓     | Updateable     | yes                  | —                           | |
| devicerec    | ✓     | Updateable     | yes                  | —                           | |
| disa         | ✓     | Updateable     | yes                  | hidden                      | DISA/CALLBACK. |
| disapass     | ✓     | Updateable     | yes                  | —                           | |
| host         | ✓     | Updateable     | NO                  | hidden                      | |
| iaxreg       | ✓     | Updateable     | no                  | hidden                           | |
| inprefix     | ✓     | Updateable     | yes                  | —                           | |
| match        | ✓     | Updateable     | yes                  | hidden                      | |
| moh          | ✓     | Updateable     | yes                  | —                           | YES/NO. |
| openroute    | ✓     | Updateable     | yes                  | guarded, hidden             | Open inbound route; default 'None'. |
| password     | ✓     | Updateable     | no                  | hidden                      | |
| peername     | ✓     | Updateable     | no                  | hidden                      | |
| pjsipreg     | ✓     | Updateable     | no                  | hidden                           | |
| privileged   | ✓     | Updateable     | yes                  | -             | |
| register     | ✓     | Updateable     | no                  | hidden                      | |
| swoclip      | ✓     | Updateable     | yes                  | —                           | YES/NO. |
| tag          | ✓     | Updateable     | yes                  | —                           | |
| technology   | ✓     | Updateable     | yes                  | guarded, hidden             | DiD/CLiD/Class; set from carrier on create. |
| transform    | ✓     | Updateable     | yes                  | hidden                      | |
| transport    | ✓     | Updateable     | no                  | hidden                          | udp default. |
| trunkname    | ✓     | Updateable     | no                  | hidden                           | |
| username     | ✓     | Updateable     | no                  | hidden                      | |
| z_created    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |
| z_updated    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |
| z_updater    | ✓     | Display only   | no                   | guarded                     | z_* never updateable. |

**DDI type:** The user supplies the value via the dropdown (DiD, CLiD, Class). The API expects **`technology`** on create and update; it is stored in the `technology` column. The legacy name `carrier` is no longer used.

---

## Gaps and mismatches

1. **Model uses $guarded and references non-DB columns**  
   `$guarded` includes many names that **do not exist** in `inroutes`: `channel`, `closecallback`, `closecustom`, `closedisa`, `closeext`, `closegreet`, `closeivr`, `closequeue`, `closesibling`, `closespeed`, `custom`, `desc`, `didnumber`, `ext`, `forceivr`, `macaddr`, `method`, `openfirewall`, `opengreet`, `opensibling`, `pat`, `postdial`, `predial`, `provision`, `queue`, `remotenum`, `service`, `speed`, `transformclip`, `trunk`, `zapcaruser`. These should be removed. **Pattern:** Replace `$guarded` with **$fillable** (whitelist of actual table columns). Model also **guards** `closeroute`, `openroute`, `privileged`, `technology` — but these **are** in the schema and in controller updateableColumns; guarding them blocks mass assignment so save/update may not persist them.

2. **Model $hidden**  
   Hides many columns that exist in schema and are in updateableColumns (callback, callerid, callprogress, host, match, password, peername, register, transform, username, disa, etc.). If these are updateable and shown in the UI, they should not be hidden. Align $hidden with your choices (e.g. only truly sensitive or deprecated fields).

3. **Controller: pkey not in updateableColumns**  
   Update path has no pkey. Create sets pkey from request. If pkey is updateable (allow renaming inbound number), add to updateableColumns and add pkey-uniqueness in `validator->after()` on update. Duplicate error on create uses key **'save'** — should use **'pkey'** and consistent message.

4. **Controller: create uses local $rules**  
   Good — does not mutate instance updateableColumns. Create requires `carrier` (request-only); sets `technology` from carrier.

5. **Controller: update**  
   No pkey-uniqueness check when pkey is present and changed. Uses `$request->cluster`; prefer `$request->input('cluster')` for consistency.

6. **SPA**  
   Ensure Inbound Route create and detail views use schema column names; openroute/closeroute as destination dropdowns (None, Operator, etc.); all updateable fields present per audit.

---

## Your input needed

1. **pkey** – Updateable (allow changing inbound number/CLID) or identity (never change)?
2. **cluster** – Keep in updateableColumns; document "not changeable in UI" if needed?
3. **closeroute, openroute** – Confirm updateable (destination names; default 'None'); model currently guards them — should be in $fillable and not hidden if UI shows them.
4. **callback, callerid, callprogress, host, match, password, peername, register, transform, username, disa, privileged, technology** – Currently hidden and/or guarded. Which are updateable and should be in $fillable and removed from $hidden? Which are display-only or sensitive (keep hidden)?
5. **carrier** – Remain request-only (maps to technology); no DB column. Confirm.
6. **Normalise openroute/closeroute** – When client sends null or 'None', treat as 'None' (like Route path1–4)? Only when key is present?
7. Any column you want **removed** from updateableColumns or **added**.

---

## Decisions applied

1. **Model** – Replaced `$guarded` with **$fillable** (all real inroutes columns except id, shortuid, z_*). **$hidden**: callprogress (legacy), host, iaxreg, password, peername, pjsipreg, register, transport, trunkname, username. Defaults: closeroute/openroute `'None'`, callprogress `'YES'` (legacy, not exposed).
2. **Controller** – **pkey** in updateableColumns (regex); duplicate error key **pkey** on create; pkey-uniqueness in update when pkey sent and changed; reject single "0" on update. **technology** in updateableColumns: `in:DiD,CLiD,Class`; create requires **technology** (user supplies via dropdown); no `carrier` request field. **trunkname** removed from updateableColumns (set in code on create when empty). **openroute/closeroute** normalised to `'None'` when key present and value null/empty (create and update). Removed from updateableColumns (legacy or not editable): callprogress, host, iaxreg, password, peername, pjsipreg, register, trunkname, username. Use `$request->input('cluster')` on update.
3. **SPA** – Create: sends **technology** (dropdown DiD, CLiD, Class); openroute/closeroute send **'None'** when None; trunkname field removed (backend sets from pkey). Detail: **pkey** editable and in save body; **technology** dropdown DiD/CLiD/Class; trunkname removed from form and body; openroute/closeroute send 'None' when None.
4. **Validation (SPA)** – `validateInboundCarrier` allows DiD, CLiD, Class.
