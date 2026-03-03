# Plan: Models vs DB alignment + API validation harmonisation

Two related tasks to bring the API in line with the actual database and with a single validation pattern.

**Approach:** Proceed with **Trunks as the prototype**. The same process (audit → your input on updateability → fix controller → harmonise validation) will then apply to other resources. Your input is required for the audit (e.g. which columns are updateable).

**Pause after each audit:** For every resource (Trunk, Queue, Agent, Extension, Route, etc.), **create the audit document first, then pause and await your input** before implementing any API or SPA changes. Do not proceed to fix the controller, model, or frontend until you have confirmed or adjusted the “Your input needed” decisions in that audit. This applies even when suggested roles seem unambiguous — always pause after the audit.

**SPA + API together:** When converting each table, you **must** update **both** the API (model, controller, validation) **and** the SPA (create and detail views for that resource) so they follow the agreed schema. Do not leave the frontend out of scope — add or remove fields, make pkey editable when the audit says updateable, and align readonly/updateable with the schema.

**Audit rule (applied by default):** Columns whose names start with **`z_`** are **never updateable** but **are displayable**.

**Model pattern (see TENANT_SCOPED_PATTERN.md):** **MUST** use **$fillable** (whitelist); do **not** use $guarded. When converting or auditing a model, replace $guarded with $fillable. Use **$hidden** only for attributes to exclude from array/JSON; can be `[]` if none; other tables may have hidden fields — adapt per resource as we audit.

---

## Task 1: Align models and validation with the database (urgency: high)

**Problem:** Models, Form Requests, and controller validation had referenced columns that no longer exist in the database (e.g. `carrier`, `sipiaxpeer`, `sipiaxuser` were removed from the `trunks` table). Those references have been removed from code and API docs. Trunk create and update use **technology** (SIP or IAX2) only; carrier does not exist.

**Source of truth for schema:**
- **Instance tables** (e.g. `trunks`, `globals`, `device`, `tt_help_core`): `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql`
- **Tenant tables** (e.g. `ipphone`, `queue`, `agent`, `route`, `ivrmenu`, `inroutes`, `cluster`): `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql`
- **Merged reference** (if used): `pbx3/full_schema.sql`

### 1.1 Audit: table-by-table (Trunk prototype first)

For each table the API touches:

1. List **actual columns** from the CREATE TABLE in the relevant SQL file.
2. Apply **rules:** e.g. columns starting with `z_` are never updateable but are displayable; identity columns (`id`, `shortuid`) are not updateable.
3. Add a **“Your input needed”** section (e.g. pkey updateable?, cluster, display-only columns, etc.).
4. **Pause and await your input.** Do not implement controller, model, or SPA changes until you have confirmed or adjusted the audit decisions.
5. After your input: compare with **model**, **controller** `updateableColumns`, and any **Form Request**; remove references to columns that don’t exist in the DB; fix controller and model; then **update the SPA** create and detail views to match.

**Trunk prototype:** See **`TRUNK_AUDIT_PROTOTYPE.md`** for the full trunk column list, suggested roles, and a short “Your input needed” section (pkey, cluster, callback, closeroute, openroute, privileged, technology). Once you’ve decided, we lock Trunk’s updateable set and fix the controller + drop TrunkRequest.

**Trunk (current design):**
- `trunks` table (instance schema): no `carrier`, `sipiaxpeer`, or `sipiaxuser`; those columns were removed.
- Trunk create requires **technology** (SIP or IAX2); the API validates and stores `technology` only. No Carrier table; no request-only carrier or sipiax* fields.
- Trunk update: validation and `updateableColumns` reference only columns that exist on `trunks`.

### 1.2 Fix Trunk (after audit input)

Once you’ve confirmed updateable columns in `TRUNK_AUDIT_PROTOTYPE.md`:

1. **TrunkRequest.php**  
   Remove from `rules()` any key that is not a column in `trunks` (instance schema). Create requires **technology** (SIP or IAX2) only; no carrier, sipiaxpeer, or sipiaxuser. For update the request will be replaced in Task 2.
2. **TrunkController**  
   - `updateableColumns`: ensure every key exists as a column in `trunks`; remove any that don’t.
   - `save()`: create requires `technology` (SIP or IAX2) and sets `$trunk->technology` from the request; no carrier or sipiax* logic.
3. **Trunk model** (see Model pattern above)  
   - Use `$fillable` (whitelist) and `$hidden` per pattern. No references to non-existent columns.

### 1.3 Audit and fix other resources

- **Extension (ipphone):** tenant schema. Check ipphone columns vs Extension model and ExtensionRequest / updateableColumns. **Then update ExtensionCreateView and ExtensionDetailView** to match (all updateable fields present; display-only not editable). **Decision needed:** `sipiaxfriend` lives on the **Device** table and is copied to extension `pjsipuser` when creating/provisioning from a device template; decide whether to expose/edit `sipiaxfriend` in the Device SPA and any Extension-side handling (see DEVICE_AUDIT_PROTOTYPE.md and ExtensionController device lookups).
- **Queue, Agent, Route, Ivr, InboundRoute, CustomApp, Tenant, HelpCore, Device, etc.:** same process — schema file → create audit doc with “Your input needed” → **pause and await your input** → then fix model + controller + Form Request and update the corresponding SPA create and detail views.
- **Instance tables:** device, tt_help_core, globals — same idea; SPA panels for those resources must be updated in the same pass.

### 1.4 Document request-only fields

Where the API accepts a field that is **not** stored (e.g. `carrier` on trunk create), document it in the controller or a small doc.

**Request-only fields (Task 1):**
- **Trunk create:** `carrier` is request-only (used to pick a template); technology is derived; sipiaxpeer/sipiaxuser come from the carrier template and are not stored in the `trunks` table. Documented in TrunkController and TRUNK_AUDIT_PROTOTYPE.md.
- Other resources: no request-only fields required for create/update in the current API.


### 1.5 Deliverables (Task 1)

- [x] Audit list: each API resource, its table(s), schema file, and any column removed from DB but still in code.
- [x] Trunk: validation and controller aligned with `trunks` (technology on create/update; no deprecated fields); **Trunk SPA (create + detail) updated to match**.
- [x] All other models/controllers/Form Requests: aligned with their tables; **for each resource, SPA create and detail views updated to match the schema** (updateable fields present, display-only not editable, pkey editable only when audit says so).
- [x] Brief note on request-only fields where relevant.

---

## Task 2: Harmonise API to Request + Validator only

**Goal:** All update actions use the same pattern: `update(Request $request, Model $model)` with a single `Validator::make($request->all(), $this->updateableColumns)` (and optional `after()` for custom rules). No Form Request for update.

**Current exception:** Extension (ExtensionRequest only). **Trunk is already done** — see below.

### 2.1 Trunk — already done

TrunkController already follows the pattern:
- `update(Request $request, Trunk $trunk)` — uses `Request`, not TrunkRequest.
- `Validator::make($request->all(), $this->updateableColumns)` with `validator->after(...)` for host validation and pkey uniqueness per cluster when pkey is present and changed.
- Create uses `save(Request $request)` with its own create rules (technology required); no Form Request.
- **Optional:** Deprecate or delete the unused `TrunkRequest.php` file (controller does not use it). SPA TrunkDetailView sends only the fields the user edits; no need to send pkey for update.

### 2.2 Extension

1. Change `update(ExtensionRequest $request, Extension $extension)` to `update(Request $request, Extension $extension)`.
2. Remove `use App\Http\Requests\ExtensionRequest`; add `use Illuminate\Validation\Rule` if using `Rule::unique` in `after()`.
3. At the start of `update()`, build validator rules: **merge** `$this->updateableColumns` with `pkey` (required) and `cluster` (required|exists:cluster,pkey), because Extension’s `updateableColumns` does not currently include `pkey`. Optionally align other rules with ExtensionRequest (e.g. `macaddr` regex, `technology` in:SIP,IAX2,DiD,CLiD,Class). Then `Validator::make($request->all(), $rules)` and `$validator->after(...)` for pkey uniqueness: when pkey is changed (submitted !== current), enforce unique per cluster with ignore of current extension id (same logic as ExtensionRequest).
4. If validation fails, return `response()->json($validator->errors(), 422)` and exit.
5. Keep the rest of the method (foreach/move to model, MAC handling, device/provision logic, save) unchanged.
6. Deprecate or delete `ExtensionRequest` (or keep for create if ever used).
7. **SPA:** ExtensionDetailView can keep sending pkey and cluster; no change required unless pkey is later made optional on update.

### 2.3 Documentation and references

1. **TENANT_SCOPED_PATTERN.md**  
   - Update the “Form Request (pkey uniqueness)” section to describe doing the same **in the controller** (Validator + after() for pkey when present/changed). Trunk and Extension both do this.
   - State that update validation is done in the controller with `updateableColumns` and optional custom rules; Form Requests are not used for update.
2. **.cursor/rules/tenant-scoped-panels.mdc** (and any similar)  
   - State that ExtensionController and TrunkController use Request + Validator; ExtensionRequest and TrunkRequest are deprecated.
3. **API docs** (e.g. general.md)  
   - Ensure request body examples and required fields for PUT reflect that update does not require pkey (or document when it does).

### 2.4 Deliverables (Task 2)

- [x] TrunkController::update uses Request + single Validator; no TrunkRequest (already done).
- [x] ExtensionController::update uses Request + Validator (with pkey uniqueness in controller after()); ExtensionRequest deprecated.
- [x] TrunkRequest and ExtensionRequest deprecated (TrunkRequest unused; ExtensionRequest no longer used by any route).
- [x] TENANT_SCOPED_PATTERN.md and cursor rules updated to reflect Request + Validator pattern; Form Requests deprecated.
- [x] Trunk save from SPA (e.g. “Active pill only”) works without sending pkey (only the fields the user edits).

---

## Task 3: Database YES/NO consistency

**Goal:** Bring the database to a consistent state by settling on **YES/NO** for boolean-like columns (e.g. `active`, `moh`, `callprogress`, `swoclip` on trunks and similar tables). API and SPA already use YES/NO; any legacy ON/OFF or other values in the DB should be migrated so API, SPA, and DB are aligned.

### 3.1 Scope

- **Document** which columns and tables use YES/NO (audit schema and list columns that are boolean-like; standardise on YES/NO).
- **One-off migration or data fix** for existing data: UPDATE any rows where those columns hold ON, OFF, or other values to YES or NO as appropriate.
- **Migration routines (SARK → PBX3):** The routines that convert old SARK databases to PBX3 must also output YES/NO for these columns when creating or transforming data, so newly migrated databases are consistent. Add or update the SARK→PBX3 migration code to normalise these fields to YES/NO.

### 3.2 Deliverables (Task 3)

- [ ] List of columns/tables in scope (YES/NO).
- [ ] Migration or script to normalise existing PBX3 data to YES/NO.
- [ ] SARK→PBX3 migration routines updated to emit YES/NO for these columns.

---

## Order and dependency

1. **Task 1 first.** Align models and validation with the DB so `updateableColumns` and rules only reference real columns.
2. **Task 2.** Switch Trunk and Extension to Request + Validator only; update docs and SPA.
3. **Task 3.** Database YES/NO consistency (data migration + SARK→PBX3 migration routines).

---

## Summary

| Task | Scope | Outcome |
|------|--------|----------|
| **1. Models vs DB** | All API models and their controllers/Form Requests **and SPA create/detail views** vs instance + tenant SQL | No validation or model attributes for columns that don’t exist; Trunk (and others) fixed; **each resource’s SPA panels updated to follow the schema**. |
| **2. Harmonise API** | Trunk + Extension update flow and docs | Single pattern: Request + Validator only; Trunk/Extension updates work like the rest of the app; SPA doesn’t need to send pkey for trunk update (only fields user edits). |
| **3. DB YES/NO** | Data + SARK→PBX3 migration | All boolean-like columns use YES/NO; existing data migrated; SARK→PBX3 migration outputs YES/NO. |
