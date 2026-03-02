# HelpCore (help messages) audit (Task 1.x)

**Purpose:** Align the HelpCore model, controller, and validation with the `tt_help_core` table. HelpCore is **instance-scoped** and has **pkey only** as identity (no id/shortuid).

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql` (table `tt_help_core`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity:** `pkey` is the sole primary key; there is no `id` or `shortuid` on this table.

---

## All columns in `tt_help_core` (from schema)

| Column      | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|-------------|-------|------------------|----------------------|-----------------------------|--------|
| pkey        | ✓     | Identity         | no (create only)     | —                           | Message key; PRIMARY KEY. |
| displayname | ✓     | Updateable       | yes                  | —                           | Display label. |
| htext       | ✓     | Updateable       | yes                  | —                           | Help text (long). |
| name        | ✓     | Deprecated       | no                   | hidden                      | Schema: "deprecated, use cname instead"; same as other panels (not in $fillable, in $hidden). |
| cname       | ✓     | Updateable/hidden | yes                  | hidden                      | Common name; hidden from API response and SPA. |
| z_created   | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updated   | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updater   | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |

---

## Gaps and mismatches

1. **Model uses $guarded only for z_***  
   HelpCore model has `$guarded = ['z_created', 'z_updated', 'z_updater']` and no `$fillable`. **Pattern:** Prefer **$fillable** (whitelist) for consistency; keep z_* out of fillable. **name** is deprecated — include in $fillable for DB read but add to **$hidden** so it is not returned in API responses (or omit from $fillable if we never need to read it).

2. **Controller**  
   updateableColumns already excludes **name** (deprecated) and only has displayname, htext, cname. Create uses local rules; duplicate error key **pkey**. pkey not updateable (identity-only). Good.

3. **SPA**  
   List shows pkey, displayname, cname (and htext truncated or in detail). Create/detail use pkey, displayname, cname, htext. No fields missing from SPA for the updateable set.

4. **name column**  
   Schema says "name is deprecated and will be dropped in a near release use cname instead". Controller does not accept or expose it. Decide: add **name** to model $hidden (and $fillable if we want to load it from DB for legacy display) or leave model as-is and never touch name.

---

## Your input needed

1. **pkey** – Confirm **identity-only** after create (no rename); keep out of updateableColumns.
2. **name** – Treat as **deprecated/hidden**: add to model $fillable (so it can be read from DB if present) and $hidden (omit from API responses), and keep out of updateableColumns? Or leave name out of the model entirely (no $fillable entry) so it is never mass-assigned or returned?
3. **z_*** – Confirmed display-only; add a **System** section on the SPA detail view (Created, Updated, Updater) like Device, or leave as-is?

---

## Decisions applied

1. **pkey** – Identity-only after create (no rename); not in updateableColumns.
2. **name** – Same as other panels (Tenant, CustomApp, Queue, etc.): **not** in $fillable (so never mass-assigned); in **$hidden** (omit from API responses). Model still loads it from DB but does not expose it.
3. **cname** – **Hidden**: in model $hidden so omitted from API responses; removed from SPA create/detail/list (no display or edit of Common name in UI). Controller keeps cname in updateableColumns (API still accepts it for direct API use).
4. **z_*** – System section added to HelpMessageDetailView: Created (z_created), Updated (z_updated), Updater (z_updater).

**Implemented:**
- **HelpCore model:** `$fillable` = pkey, displayname, htext, cname; `$hidden` = ['name', 'cname']; removed `$guarded`.
- **HelpCoreController:** No change (updateableColumns unchanged; cname still accepted on create/update).
- **SPA:** HelpMessageDetailView – removed Common name field, added System section (z_created, z_updated, z_updater). HelpMessageCreateView – removed Common name field. HelpMessagesListView – removed Common name column and filter by cname.
