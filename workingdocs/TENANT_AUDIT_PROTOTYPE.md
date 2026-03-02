# Tenant (cluster) audit (Task 1.x)

**Purpose:** Align the Tenant model, controller, and validation with the `cluster` table (tenants). Same pattern as Trunk, Route, Queue, Agent, IVR, InboundRoute, Extension, and CustomApp.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `cluster`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `cluster` (from schema)

| Column          | In DB | Suggested role         | In updateableColumns | In model $guarded / $hidden | Notes |
|-----------------|-------|------------------------|----------------------|-----------------------------|--------|
| id              | ✓     | Identity               | no                   | —                           | KSUID, set on create. PRIMARY KEY. |
| shortuid        | ✓     | Identity               | no                   | —                           | Set on create. UNIQUE. |
| pkey            | ✓     | Identity               | no                   | —                           | Tenant name/key; unique per system. |
| abstimeout      | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| acl             | ✓     | Updateable (bool)      | no                   | —                           | Hidden. |
| active          | ✓     | Updateable             | yes                  | —                           | YES/NO. |
| allow_hash_xfer | ✓     | Updateable             | yes                  | —                           | enabled/disabled. |
| blind_busy      | ✓     | Updateable             | yes                  | —                           | TEXT. |
| bounce_alert    | ✓     | Updateable             | yes                  | —                           | TEXT. |
| callrecord_1    | ✓     | Updateable             | yes                  | —                           | None/In/Out/Both. |
| camp_on_q_onoff | ✓     | Updateable             | yes                  | —                           | TEXT. |
| camp_on_q_opt   | ✓     | Updateable             | yes                  | —                           | TEXT. |
| cfwdextern_rule | ✓     | Updateable             | yes                  | —                           | YES/NO. |
| cfwd_progress   | ✓     | Updateable             | yes                  | —                           | enabled/disabled. |
| cfwd_answer     | ✓     | Updateable             | yes                  | —                           | enabled/disabled. |
| clusterclid     | ✓     | Updateable             | yes                  | —                           | CLID; numeric string. |
| chanmax         | ✓     | Updateable             | yes                  | —                           | INTEGER; max channels. |
| cname           | ✓     | Updateable             | yes                  | —                           | Common name. |
| countrycode     | ✓     | Updateable             | yes                  | —                           | INTEGER; default 44. |
| dynamicfeatures | ✓     | Updateable             | yes                  | —                           | TEXT. |
| description     | ✓     | Updateable             | yes                  | —                           | Short description. |
| devicerec       | ✓     | Updateable             | yes                  | —                           | Recording settings; default 'default'. |
| emailalert      | ✓     | Updateable             | yes                  | —                           | TEXT. |
| emergency       | ✓     | Updateable             | yes                  | —                           | Default emergency numbers. |
| extblklist      | ✓     | Deprecated / internal  | no                  | —                           | TEXT (needs work). |
| ext_lim         | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| ext_len         | ✓     | Updateable             | yes                  | —                           | INTEGER; extension length. |
| fqdn            | ✓     | Updateable             | yes                  | —                           | TEXT. |
| fqdninspect     | ✓     | Updateable (bool)      | yes                  | —                           | BOOLEAN. |
| include         | ✓     | Deprecated / internal  | no                  | —                           | TEXT; other tenants short-dial list. |
| int_ring_delay  | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| ivr_key_wait    | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| ivr_digit_wait  | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| language        | ✓     | Updateable             | yes                  | —                           | TEXT; default 'en-gb'. |
| ldapanonbind    | ✓     | Updateable             | yes                  | —                           | YES/NO. |
| ldapbase        | ✓     | Updateable             | yes                  | —                           | TEXT. |
| ldaphost        | ✓     | Updateable             | yes                  | —                           | TEXT. |
| ldapou          | ✓     | Updateable             | yes                  | —                           | TEXT. |
| ldapuser        | ✓     | Updateable             | yes                  | —                           | TEXT. |
| ldappass        | ✓     | Updateable             | yes                  | —                           | TEXT. |
| ldaptls         | ✓     | Updateable             | yes                  | —                           | 'on' / 'off'. |
| localarea       | ✓     | Updateable             | yes                  | —                           | TEXT; local area code. |
| localdplan      | ✓     | Updateable             | yes                  | —                           | TEXT; local dialplan. |
| lterm           | ✓     | Updateable             | yes                  | —                           | Late termination | 0/1 (yes/no)
| leasedhdtime    | ✓     | Future feature         | no                  | hidden                           | INTEGER; hot desk lease time. | hidden
| masteroclo      | ✓     | Updateable             | yes                  | —                           | 'AUTO' or 'CLOSED'. |
| maxin           | ✓     | Updateable             | yes                  | —                           | INTEGER; max inbound calls. |
| maxout          | ✓     | Updateable             | yes                  | —                           | INTEGER; max outbound calls. |
| mixmonitor      | ✓     | Updateable             | yes                  | —                           | TEXT. |
| monitor_out     | ✓     | Updateable             | yes                  | —                           | TEXT; folder. |
| monitor_stage   | ✓     | Updateable             | yes                  | guarded                      | Deprecated? stage folder; candidate display-only. |
| name            | ✓     | Deprecated / internal  | no                   | guarded, hidden             | Schema name; likely legacy; use pkey/cname instead. |
| number_range_regex | ✓  | Deprecated / internal  | no                   | guarded, hidden                           | TEXT; number range. |
| oclo            | ✓     | Deprecated / internal  | no                   | guarded, hidden             | Legacy open/close? superseded by masteroclo. |
| operator        | ✓     | Updateable             | yes                  | —                           | INTEGER; operator extension. |
| padminpass      | ✓     | Deprecated / internal | no                  | —                           | ADMIN password (phone browser). |
| puserpass       | ✓     | Deprecated / internal | no                  | —                           | USER password (phone browser). |
| pickupgroup     | ✓     | Deprecated / internal  | no                   | —                           | TEXT. |
| play_beep       | ✓     | Updateable             | yes                  | —                           | INTEGER flag. |
| play_busy       | ✓     | Updateable             | yes                  | —                           | INTEGER flag. |
| play_congested  | ✓     | Updateable             | yes                  | —                           | INTEGER flag. |
| play_transfer   | ✓     | Updateable             | yes                  | —                           | INTEGER flag. |
| rec_age         | ✓     | Updateable             | yes                  | —                           | INTEGER; days. |
| rec_final_dest  | ✓     | Updateable             | yes                  | —                           | TEXT; recordings folder. |
| rec_file_dlim   | ✓     | Updateable             | yes                  | —                           | TEXT; filename delimiter. |
| rec_grace       | ✓     | Updateable             | yes                  | —                           | INTEGER; grace days. |
| rec_limit       | ✓     | Updateable             | yes                  | —                           | INTEGER; folder max size. |
| rec_mount       | ✓     | Updateable             | yes                  | —                           | TEXT; mount command. |
| recmaxage       | ✓     | Updateable             | yes                  | —                           | TEXT; days string. |
| recmaxsize      | ✓     | Updateable             | yes                  | —                           | TEXT; storage max. |
| recused         | ✓     | Display / system       | no                   | —                           | TEXT; used storage; system-maintained. |
| ringdelay       | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| routeoverride   | ✓     | Display / system       | no                   | guarded                     | Holiday scheduler override; may be system-managed. |
| spy_pass        | ✓     | Updateable (sensitive) | yes                  | —                           | Spy password. |
| sysop           | ✓     | Updateable             | yes                  | —                           | INTEGER; real operator ext. |
| syspass         | ✓     | Updateable (sensitive) | yes                  | —                           | System password. |
| usemohcustom    | ✓     | Updateable             | yes                  | —                           | TEXT. |
| VDELAY          | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| vmail_age       | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| voice_instr     | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| voip_max        | ✓     | Updateable             | yes                  | —                           | INTEGER. |
| vxt             | ✓     | Updateable (flag)      | yes                  | guarded/hidden              | INTEGER default 0; enable/disable VXT. |
| z_created       | ✓     | Display only           | no                   | guarded                     | z_* never updateable. |
| z_updated       | ✓     | Display only           | no                   | guarded                     | z_* never updateable. |
| z_updater       | ✓     | Display only           | no                   | guarded                     | z_* never updateable. |

---

## Gaps and mismatches (current code)

1. **Model uses $guarded and primaryKey = 'pkey'**  
   Schema PRIMARY KEY is **id** (KSUID). Tenant model currently sets `protected $primaryKey = 'pkey'`, and `$guarded` includes `name`, `oclo`, `routeclassoverride`, `routeoverride`, `z_*`; `$hidden` hides `name`, `oclo`. Other tenant models now use **id** primaryKey with `resolveRouteBinding` by shortuid.  
   **Impact:** Updates and routing are pkey-based, not id-based; `name`/`oclo` treatment needs to be explicit (deprecated vs system-managed).

2. **Controller: create mutates $updateableColumns**  
   `save()` adds `pkey` and `description` rules directly into `$updateableColumns`, mutating the shared structure used by update. Pattern for other controllers is to use **local create rules** and leave `$updateableColumns` as the stable update set.

3. **Controller: duplicate check is global (no cluster)**  
   `Tenant::where('pkey', '=', $request->pkey)->count()` checks pkey uniqueness across all tenants. There is no `(cluster, pkey)` uniqueness constraint for `cluster` (tenant itself is the top-level entity), so global uniqueness is acceptable, but we should document it.

4. **Controller: pkey not updateable**  
   `updateableColumns` excludes pkey; SPA TenantDetailView treats pkey as read-only identity. This matches the current UI (no rename of tenant name).

5. **Controller: many fields are in updateableColumns**  
   `$updateableColumns` contains almost all non-identity, non-z_* cluster columns. This matches the schema but there may be fields you consider **system-only** (e.g. `monitor_stage`, `routeoverride`, `recused`, some LDAP defaults) that should be display-only.

6. **SPA**  
   TenantCreateView and TenantDetailView only expose a **subset** of cluster fields (pkey, description, clusterclid, abstimeout, chanmax, masteroclo + “Advanced” subset). Many fields in `$updateableColumns` are not surfaced in UI, which is acceptable but should be documented as advanced / API-only.

---

## Decisions applied

1. **pkey** – Identity-only (never changed after create). SPA and controller keep it read-only; model now uses `id`/`shortuid` internally with route binding by shortuid then pkey.
2. **Deprecated / internal fields**  
   - `acl` – Deprecated; hidden and not in updateableColumns (no longer used in API/SPA).  
   - `extblklist`, `include`, `leasedhdtime`, `monitor_stage`, `number_range_regex`, `routeoverride`, `recused`, `padminpass`, `puserpass`, `pickupgroup` – Not in updateableColumns; system-only/display-only. Still readable from DB/API where needed, but not editable via general tenant update.  
   - `name`, `oclo` – Legacy, hidden and not updateable.  
   - `vxt` – Reserved for future; hidden and removed from API/SPA.
3. **Model (Tenant):**  
   - `primaryKey = 'id'`; `resolveRouteBinding` resolves by `shortuid` then `pkey`.  
   - **$fillable**: real, editable cluster columns (pkey + all agreed updateable fields), excluding id/shortuid/z_* and deprecated/system-only fields listed above.  
   - **$hidden**: `name`, `oclo`, `acl`, `vxt`.
4. **Controller (TenantController):**  
   - `save()` uses local `$createRules` (no mutation of `$updateableColumns`), requiring `pkey` and `description`.  
   - `$updateableColumns` excludes deprecated/system-only fields (`acl`, `extblklist`, `include`, `leasedhdtime`, `monitor_stage`, `number_range_regex`, `padminpass`, `puserpass`, `pickupgroup`, `recused`, `vxt`).  
   - pkey remains non-updateable; global pkey uniqueness enforced on create.
5. **SPA:**  
   - TenantCreateView and TenantDetailView continue to expose pkey (read-only on detail), description, clusterclid, abstimeout, chanmax, masteroclo and the curated “Advanced” subset.  
   - No UI for deprecated/system-only fields (extblklist, include, vxt, passwords, etc.); those are API/internal only as per above.


