# Agent audit (Task 1.3)

**Purpose:** Align the Agent model, controller, and validation with the `agent` table. Same process as Trunk, Queue, and Extension audits.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql` (table `agent`).

**Rules applied:**
- Columns whose names start with **`z_`** are **never updateable** but **are displayable**.
- **Identity columns** set by the system on create: `id`, `shortuid` → **not updateable**, displayable.

---

## All columns in `agent` (from schema)

| Column    | In DB | Role           | In updateableColumns | In model $fillable / $hidden | Notes |
|-----------|-------|----------------|----------------------|------------------------------|--------|
| id        | ✓     | Identity       | no                   | —                            | KSUID, set on create. Not updateable. |
| shortuid  | ✓     | Identity       | no                   | —                            | Set on create. Not updateable. |
| pkey      | ✓     | Updateable     | yes                  | yes                          | Agent number; 1000–9999; unique per cluster. |
| cluster   | ✓     | Updateable     | yes                  | yes                          | Tenant; store shortuid. |
| conf      | ✓     | Display/fixed  | no                   | yes, hidden                  | System/config; not user-editable. |
| extlen    | ✓     | Updateable (API only) | yes            | yes                          | INTEGER, nullable. Not shown in Agent UI. |
| name      | ✓     | Display/deprecated | no               | yes, hidden                  | Deprecated; use cname. |
| cname     | ✓     | Updateable     | yes                  | yes                          | Common name. |
| description | ✓   | Updateable     | yes                  | yes                          | |
| num       | ✓     | Display/fixed  | no                   | yes, hidden                  | Internal number. |
| passwd    | ✓     | Updateable     | yes                  | yes                          | Agent PIN; 1001–9999. |
| queue1–6  | ✓     | Updateable     | yes                  | yes                          | DEFAULT 'None'; exists:queue,pkey. |
| z_created | ✓     | Display only   | no                   | —                            | z_* never updateable. |
| z_updated | ✓     | Display only   | no                   | —                            | z_* never updateable. |
| z_updater | ✓     | Display only   | no                   | —                            | z_* never updateable. |

---

## Decisions applied

1. **Model:** Use **$fillable** (whitelist); no $guarded. Fillable = all table columns except id, shortuid, z_*. **$hidden** = `conf`, `num`, `name` (name deprecated; conf/num internal).
2. **Controller updateableColumns:** `pkey`, `cluster`, `cname`, `description`, `extlen`, `passwd`, `queue1`–`queue6`. **Removed** `name` (deprecated).
3. **Create:** Sets `id` and `shortuid` before save(); validates pkey unique per tenant in `validator->after()`. Create rules: pkey required|integer|1000–9999, cluster required, passwd required|integer|1001–9999; cname, description, extlen, queue1–6 optional.
4. **Update:** Validator + `validator->after()` for pkey uniqueness when pkey is present and changed (same pattern as Queue). Update by id only.
5. **SPA:** Create and detail use Agent number (pkey), Common name (cname), Description, Password, Tenant, Queues 1–6. **extlen** is not shown in the Agent UI (API may still accept it). No "Name" field (deprecated); cname is the display name.

---

## Summary

- **Agent model:** $fillable list; $hidden = conf, num, name.
- **AgentController:** updateableColumns include pkey; name removed; save() does not mutate instance updateableColumns; create uses local rules; update() has pkey-uniqueness after().
- **SPA AgentCreateView:** Required pkey, cluster, passwd; optional cname, description, extlen, queue1–6; no name field.
- **SPA AgentDetailView:** Editable pkey, cname, description, extlen, passwd, cluster, queue1–6; save body includes all updateable fields; name not editable (deprecated).
