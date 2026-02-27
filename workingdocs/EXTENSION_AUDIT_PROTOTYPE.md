# Extension audit (prototype for Task 1)

**Purpose:** Decide which columns on `ipphone` (extensions) are updateable vs display-only, so we can align the controller, model, and ExtensionRequest with the database. Same process as Trunk audit.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `ipphone`).

**Rules applied so far:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable** (per Trunk rule).
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `ipphone` (from schema)

| Column        | In DB | Suggested role   | In updateableColumns | In ExtensionRequest | In model $guarded / $hidden | Notes |
|---------------|-------|------------------|----------------------|---------------------|-----------------------------|--------|
| id            | ✓     | Identity         | no                   | no                  | —                           | KSUID, set on create. Not updateable. |
| shortuid      | ✓     | Identity         | no                   | no                  | —                           | Set on create. Not updateable. |
| pkey          | ✓     | Updateable       | no                   | yes (required)      | —                           | Extension number; unique per cluster. Rename allowed? |
| abstimeout    | ✓     | Updateable  | no                   | no                  | guarded                     | INTEGER DEFAULT 1440. |
| active        | ✓     | Updateable       | yes                  | yes                 | —                           | YES/NO. |
| basemacaddr  | ✓     | Display / fixed  | no                   | no                  | guarded                     | Comment: not used. |
| callerid      | ✓     | Updateable       | yes                  | yes (integer)       | —                           | Schema TEXT; Request has integer\|nullable. |
| callbackto    | ✓     | Updateable       | yes                  | yes                 | —                           | desk/cell. |
| cname         | ✓     | Updateable       | yes                  | no                  | —                           | Common name. |
| callmax       | ✓     | Updateable       | yes                  | no                  | —                           | INTEGER DEFAULT 3. |
| cellphone     | ✓     | Updateable       | yes                  | yes (integer)       | —                           | Schema TEXT; Request has integer\|nullable. |
| celltwin      | ✓     | Updateable       | yes                  | yes                 | —                           | ON/OFF. |
| cluster       | ✓     | Updateable       | yes                  | yes                 | —                           | Tenant; may be “not changeable in UI” like trunk. |
| desc          | ✓     | Updateable       | yes                  | yes                 | —                           | SIP username; used by Asterisk generator. |
| description   | ✓     | Updateable       | yes                  | no                  | —                           | Freeform. |
| device        | ✓     | Updateable       | yes                  | yes                 | —                           | Device vendor. |
| devicemodel   | ✓     | Display / fixed  | no                   | no                  | guarded                     | Harvested model. |
| devicerec     | ✓     | Updateable       | yes                  | yes                 | —                           | default/None/Inbound/Outbound/Both. |
| dvrvmail      | ✓     | Updateable       | yes                  | yes                 | —                           | Mailbox; exists:ipphone,pkey. |
| extalert      | ✓     | Updateable       | yes                  | no                  | —                           | Alert info. |
| macaddr       | ✓     | Updateable       | yes                  | yes                 | —                           | 12 hex; MAC validation in controller. |
| passwd        | ✓     | Display / fixed  | no                   | no                  | guarded                     | Asterisk password; set on create. |
| protocol      | ✓     | Updateable       | yes                  | yes                 | —                           | IPV4/IPV6. |
| provision     | ✓     | Updateable       | yes                  | no                  | —                           | Provisioning string; controller can set from device. |
| provisionwith | ✓     | Updateable       | yes                  | no                  | —                           | IP/FQDN. |
| pjsipuser     | ✓     | Updateable       | yes                  | no                  | —                           | Asterisk PJSIP string. |
| stealtime     | ✓     | Display only     | no                   | no                  | guarded                     | Epoch; HD steal. |
| stolen        | ✓     | Display only     | no                   | no                  | guarded                     | HD thief. |
| technology    | ✓     | Updateable       | yes                  | yes                 | —                           | SIP/IAX2/DiD/CLiD/Class; default SIP. |
| tls           | ✓     | Display / fixed  | no                   | no                  | guarded                     | TLS on/off. |
| transport     | ✓     | Updateable       | yes                  | yes                 | —                           | udp/tcp/tls/wss. |
| vmailfwd      | ✓     | Updateable       | yes                  | yes                 | —                           | Email. |
| z_created     | ✓     | Display only     | no                   | no                  | —                           | z_* never updateable. |
| z_updated     | ✓     | Display only     | no                   | no                  | —                           | z_* never updateable. |
| z_updater     | ✓     | Display only     | no                   | no                  | —                           | z_* never updateable. |

**Not in DB (remove from model/validation):**  
- **Model $guarded / $hidden:** `dialstring`, `firstseen`, `lastseen`, `sndcreds`, `newformat`, `openfirewall`, `twin`, `channel`, `externalip`, `sipiaxfriend` — not columns in `ipphone`; safe to remove from Extension model to avoid confusion.  
- **Controller create:** `location` is passed to `Extension::create()` in mailbox(), unprovisioned(), webrtc(), provisioned() but **does not exist** in `ipphone` schema; may be ignored by DB or indicate schema drift.

---

## Gaps and mismatches

1. **ExtensionController::$updateableColumns**  
   - Includes only columns that exist in DB; no non-DB keys.  
   - Does **not** include `pkey` (update allows rename via request; uniqueness handled in ExtensionRequest).

2. **ExtensionRequest**  
   - **Added rules:** `cname`, `description` (string|nullable), `callmax` (integer|nullable), `extalert`, `provision`, `pjsipuser` (text = string|nullable), `provisionwith` (text; only `IP` or `FQDN`, default IP → in:IP,FQDN), `technology` (text; only `SIP`, `IAX2`, `DiD`, `CLiD`, `Class`, default SIP → nullable|in:SIP,IAX2,DiD,CLiD,Class).  
   - **callerid**, **cellphone:** fixed to `string|nullable` to match schema TEXT.

3. **Extension model**  
   - `$guarded` / `$hidden` reference non-DB columns (see above); clean up to only DB columns.  
   - `$attributes` and constructor set `passwd` via `ret_password(12)` on create; keep passwd guarded.

4. **update() flow**  
   - Uses **ExtensionRequest** for validation; applies only keys present in `$updateableColumns`.  
   - MAC/device/provision logic is in controller (correct).  
   - Harmonisation (Task 2) will switch to Request + Validator in controller; until then, fixing ExtensionRequest to match DB and updateable set is enough.

---

## Your input needed

1. **pkey** – Updateable (allow extension number rename) or treat as identity and exclude from update?
2. **cluster** – Keep in updateableColumns but document “not changeable in UI for now” / force default, or remove from updateable?
3. **abstimeout, basemacaddr, devicemodel, passwd, stealtime, stolen, tls** – Confirm display-only / system-managed (no client update).
4. **provision, provisionwith, pjsipuser, technology** – Confirm updateable (controller may set from device/MAC) or read-only?
5. **callerid / cellphone** – Keep as nullable; fix type in ExtensionRequest to `string|nullable` to match schema TEXT?
6. Any column you want **removed** from updateableColumns (e.g. cluster if client never changes it).
7. Any column you want **added** to updateableColumns that is in schema but not currently listed.

Once you’ve marked these, we’ll:
1. Set `updateableColumns` in ExtensionController to the agreed set (with validation rules).
2. Align ExtensionRequest rules with that set (and fix callerid/cellphone to string).
3. Clean Extension model: remove non-DB names from `$guarded` and `$hidden`.
4. Optionally document or fix `location` on create (schema vs code).
