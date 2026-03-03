# Globals (sysglobals) audit (Task 1.x)

**Purpose:** Align the Sysglobal model and controller with the `globals` table. Globals is **instance-scoped**, **single-row** (one row of system settings): no list by key, no POST (create), no DELETE. API: GET sysglobals (returns the one row), PUT sysglobals (update that row).

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql` (table `globals`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity:** `pkey` is the sole primary key (typically one row, e.g. `pkey = 'default'`). Not updateable via API (single row; no create/delete).

---

## All columns in `globals` (from schema)

Schema column names are **lowercase**. The table has 39 data columns plus pkey and z_*.

| Column          | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|-----------------|-------|------------------|----------------------|-----------------------------|--------|
| pkey            | ✓     | Identity         | no                   | guarded, hidden             | Single row key; not updateable. |
| abstimeout      | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 14400. |
| bindaddr        | ✓     | Updateable       | yes                  | —                           | Asterisk SIP bindaddr. |
| bindport        | ✓     | Updateable       | yes                  | —                           | TEXT DEFAULT 5060. |
| cosstart        | ✓     | Updateable       | yes                  | —                           | DEFAULT 'ON'. |
| edomain         | ✓     | display only         | no                  | —                           | External IP / domain. |
| emergency       | ✓     | Updateable       | yes                  | —                           | DEFAULT '999 112 911'. |
| fqdn            | ✓     | Updateable       | yes                  | —                           | |
| fqdninspect     | ✓     | hidden           | no                   | hidden                          | DEFAULT 'NO'. |
| fqdnprov        | ✓     | hidden      | no                  | —                           | FQDN in provisioning YES/NO. |
| language        | ✓     | Updateable       | yes                  | —                           | DEFAULT 'en-gb'. |
| localip         | ✓     | display only       | no                  | —                           | |
| loglevel        | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 0. |
| logopts         | ✓     | Updateable       | yes                  | —                           | |
| logsipdispsize  | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 2000. |
| logsipnumfiles  | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 10. |
| logsipfilesize  | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 20000. |
| maxin           | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 30. |
| maxout          | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 30. |
| mycommit        | ✓     | hidden       | yes                  | —                           | |
| natdefault      | ✓     | Updateable       | yes                  | —                           | DEFAULT 'remote'. |
| natparams       | ✓     | Updateable       | yes                  | —                           | DEFAULT 'force_rport,comedia'. |
| operator        | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 100. |
| pwdlen          | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 12. |
| recfiledlim     | ✓     | Updateable       | yes                  | —                           | DEFAULT '_-_'. |
| reclimit        | ✓     | Updateable       | yes                  | —                           | |
| recmount        | ✓     | Updateable       | yes                  | —                           | |
| recqdither      | ✓     | Updateable       | yes                  | —                           | |
| recqsearchlim   | ✓     | Updateable       | yes                  | —                           | |
| sessiontimout   | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 600. |
| sendedomain     | ✓     | Updateable       | yes                  | —                           | DEFAULT 'YES'. |
| sipflood        | ✓     | Updateable       | yes                  | —                           | DEFAULT 'NO'. |
| sipdriver       | ✓     | Display only      | no                  | —                           | DEFAULT 'PJSIP'. |
| sitename        | ✓     | Updateable       | yes                  | —                           | |
| staticipv4      | ✓     | hidden       | yes                  | —                           | |
| sysop           | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 100. |
| syspass         | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 4444. |
| tlsport         | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 5061. |
| userotp         | ✓     | Deprecated/hidden | no                   | hidden                      | Deprecated; not shown in UI or accepted on update. |
| vcl             | ✓     | hidden       | yes                  | —                           | DEFAULT '1'. |
| voipmax         | ✓     | Updateable       | yes                  | —                           | INTEGER DEFAULT 30. |
| z_created       | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updated       | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updater       | ✓     | Display only     | no                   | —                           | z_* never updateable. (Model currently has z_created, z_updated in $guarded only.) |

---

## Gaps and mismatches

1. **Model uses $guarded and $hidden with UPPERCASE names not in schema**  
   The schema has **lowercase** column names only (abstimeout, bindaddr, …). The model's `$guarded` and `$hidden` list many **UPPERCASE** names (e.g. ASTDLIM, ATTEMPTRESTART, BINDADDR, CDR, CFEXTERN, …) that **do not exist** in `sqlite_create_instance.sql`. These look like legacy or a different schema. **Pattern:** Use **$fillable** (whitelist of actual schema columns); guard only pkey and z_* from mass assignment; use **$hidden** only for fields to omit from JSON (e.g. pkey if desired, or none). Remove all non-existent column names from guarded/hidden.

2. **Model has no $fillable**  
   Per plan, use **$fillable** (whitelist). All updateable columns (every column in schema except pkey and z_*) should be in $fillable so that `move_request_to_model` and update() can assign them.

3. **Controller**  
   updateableColumns match the schema (lowercase; 39 columns). No pkey in updateableColumns (correct). index() returns first row; update() uses Request + Validator. No POST/DELETE. Good.

4. **z_updater**  
   Model has z_created, z_updated in $guarded but list shows z_updater in guarded too — model actually has 'z_updated' only (no z_updater in the guarded list in the file). Checking: model guarded has 'z_created', 'z_updated' (and no z_updater). So z_updater is not guarded; add to guarded or leave assignable. For consistency, z_* should all be non–mass-assignable (guarded or simply not in $fillable).

5. **SPA**  
   SysglobalsEditView has all 41 fields (comment says 41; controller has 39 updateableColumns — same set, SPA includes all). SPA sends body with lowercase keys. No list view (single row). Optional: add System section (z_created, z_updated, z_updater) to the edit view like Device/HelpCore.

---

## Your input needed

1. **pkey** – Confirm **identity-only**, never updateable; keep out of updateableColumns. Single row: pkey can remain in $hidden so it’s not returned in JSON, or be visible (read-only). Prefer hidden or visible?
2. **Model cleanup** – Replace $guarded/$hidden with **$fillable** (all schema columns except pkey and z_*) and **$hidden** = ['pkey'] only (or [] if pkey should be returned). Remove all UPPERCASE names that are not in the schema.
3. **z_*** – Keep z_created, z_updated, z_updater out of $fillable; add z_updater to $guarded if missing, or rely on “not in $fillable”. Prefer no $guarded and just $fillable (so z_* are never mass-assigned).
4. **SPA** – Add a **System** section (z_created, z_updated, z_updater) to SysglobalsEditView for consistency with Device/HelpCore, or leave as-is?

---

## Decisions applied

1. **pkey** – Identity-only; in **$hidden** (not returned in JSON).
2. **Display-only** (edomain, localip, sipdriver) – Removed from updateableColumns; shown as **read-only** fields in SPA.
3. **Hidden** (fqdninspect, fqdnprov, mycommit, staticipv4, vcl) – Removed fqdninspect, fqdnprov from updateableColumns (not updateable). mycommit, staticipv4, vcl kept in updateableColumns (API accepts) but in model **$hidden** (omitted from response). SPA: removed all five from form (not returned by API).
4. **Model** – Replaced $guarded/$hidden with **$fillable** (all 39 schema columns, lowercase); **$hidden** = ['pkey', 'fqdninspect', 'fqdnprov', 'mycommit', 'staticipv4', 'userotp', 'vcl']; removed all non-schema UPPERCASE names.
5. **Controller** – Removed from updateableColumns: edomain, fqdninspect, fqdnprov, localip, sipdriver. **userotp** removed (deprecated).
6. **SPA** – edomain, localip, sipdriver as FormReadonly; removed fqdninspect, fqdnprov, mycommit, staticipv4, userotp (deprecated), vcl from form; added **System** section (z_created, z_updated, z_updater). Removed display-only and hidden fields from save body.
7. **userotp** – Deprecated: in model **$hidden**; removed from controller updateableColumns and from SPA form/save.
