# Device audit (Task 1.x)

**Purpose:** Align the Device model, controller, and validation with the `device` table (provisioning templates). Device is **instance-scoped** and has **pkey only** as identity (no id/shortuid).

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql` (table `device`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity:** `pkey` is the sole primary key; there is no `id` or `shortuid` on this table.

---

## All columns in `device` (from schema)

| Column        | In DB | Suggested role   | In updateableColumns | In model $guarded / $hidden | Notes |
|---------------|-------|------------------|----------------------|-----------------------------|--------|
| pkey          | ✓     | Identity         | no (create only)     | —                           | Template name; PRIMARY KEY. |
| blfkeyname    | ✓     | Updateable       | yes                  | —                           | BLF key template name. |
| blfkeys       | ✓     | Updateable       | yes                  | —                           | INTEGER; number of BLF keys. |
| desc          | ✓     | Updateable       | yes                  | —                           | Description label. |
| device        | ✓     | Updateable?      | no                   | hidden                           | Device/vendor identifier; not in controller today. |
| fkeys         | ✓     | Updateable       | yes                  | hidden                      | INTEGER. |
| imageurl      | ✓     | Deprecated/hidden       | no                  | hidden                       | TEXT. |
| legacy        | ✓     | Updateable       | yes                  | —                           | TEXT (legacy flag). |
| noproxy       | ✓     | Deprecated/hidden | no                  | hidden                       | TEXT. |
| owner         | ✓     | Updateable       | yes                  | —                           | Default 'system'. |
| pkeys         | ✓     | Updateable       | yes                  | —                           | INTEGER. |
| provision     | ✓     | Updateable       | yes                  | —                           | Provisioning template (long text). |
| sipiaxfriend  | ✓     | Updateable       | yes                  | hidden                      | SIP/IAX config snippet (long text). |
| technology    | ✓     | Updateable       | yes                  | —                           | SIP, IAX2, etc.; SPA uses SIP/Descriptor/BLF Template. |
| tftpname      | ✓     | Deprecated/hidden | no                  | hidden                       | TEXT. |
| zapdevfixed   | ✓     | Deprecated/hidden | no                  | hidden                       | TEXT; analogue/zap. |
| z_created     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updated     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |
| z_updater     | ✓     | Display only     | no                   | guarded                     | z_* never updateable. |

---

## Gaps and mismatches

1. **Model uses $guarded only for z_***  
   Device model has `$guarded = ['z_created', 'z_updated', 'z_updater']` and no `$fillable`. **Pattern:** Prefer **$fillable** (whitelist of real device columns) for consistency with other panels; keep z_* out of fillable.

2. **Controller: `device` column not in updateableColumns**  
   The schema has a **`device`** column (device/vendor identifier). The controller does **not** include it in `$updateableColumns`. Confirm whether it should be updateable or display-only/system-only.

3. **Controller: create uses local rules**  
   Create already uses `$rules = array_merge(['pkey' => 'required|string'], $this->updateableColumns)` and does not mutate `$updateableColumns`. Duplicate error uses key **pkey** (good).

4. **pkey not updateable**  
   Update does not allow changing pkey (no pkey in updateableColumns). So template name is fixed after create. SPA detail shows pkey read-only. Confirm: pkey **identity-only** after create?

5. **SPA**  
   Create sends pkey, desc, technology, provision, owner. Detail edits desc, technology, provision, owner only. Many columns (blfkeyname, blfkeys, device, fkeys, imageurl, legacy, noproxy, pkeys, tftpname, zapdevfixed) are not in the SPA forms — acceptable as API-only or advanced.

6. **Technology values**  
   SPA uses `['SIP', 'Descriptor', 'BLF Template']`; schema/data also use values like `'IAX2'`, `'Analogue'`, `'Custom'`. Confirm allowed set for validation (e.g. restrict to SPA set or allow any string).

---

## Your input needed

1. **pkey** – Confirm **identity-only** after create (no rename); keep out of updateableColumns.
2. **device** column – Add to updateableColumns (and SPA if desired), or keep **display-only** / system-only and leave out of updateableColumns?
3. **provision / sipiaxfriend** – Long text (like CustomApp extcode). Should SPA use textarea/longtext for these on create and detail?
4. **technology** – Restrict validation to a fixed set (e.g. SIP, Descriptor, BLF Template, IAX2, Analogue, Custom) or allow any string?
5. **Other columns** – Any to treat as **display-only** or **hidden** (e.g. legacy, zapdevfixed)? Any to **add** to SPA create/detail?

---

## Decisions applied

1. **pkey** – Identity-only after create; not in updateableColumns.
2. **device** – Never accepted on create or update; in model `$fillable` (for DB read) and `$hidden` (omitted from API responses).
3. **Updateable but hidden** – API accepts **sipiaxfriend** and **fkeys** on create/update; both are in `$hidden` so omitted from all API responses.
4. **Deprecated/hidden** – **imageurl**, **noproxy**, **tftpname**, **zapdevfixed** removed from updateableColumns; in model `$fillable` and `$hidden`.
5. **Technology** – Validation restricted to `SIP`, `Descriptor`, `BLF Template` (same as SPA).
6. **Typos** – "Updateablen" → "Updateable"; "Deprectated" → "Deprecated".

**Implemented:**
- **Device model:** `$fillable` = all real device columns (except z_*); `$hidden` = device, fkeys, imageurl, noproxy, sipiaxfriend, tftpname, zapdevfixed; removed `$guarded`.
- **DeviceController:** updateableColumns = blfkeyname, blfkeys, desc, fkeys, legacy, owner, pkeys, provision, sipiaxfriend, technology (device and deprecated columns excluded); technology rule `nullable|string|in:SIP,Descriptor,BLF Template`; create rules unchanged (pkey required, duplicate key `pkey`).
- **SPA:** No change in this pass (create/detail still pkey, desc, technology, provision, owner).
