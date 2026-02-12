# API & SPA vs running_schema.sql – compliance audit

**Source of truth:** `pbx3spa/running_schema.sql` (dump from running SQLite).

This doc records checks of all API controllers and SPA panels against the schema so only existing columns are used.

---

## Summary of fixes applied

| Resource | Issue | Fix |
|----------|--------|-----|
| **Trunk** | Validation included `sipiaxpeer`, `sipiaxuser`; `trunks` table has no such columns. | Removed those two from validation in `TrunkController`. Create already unsets them before save. |
| **Route** | API used `desc`; `route` table has `description` and `cname`, not `desc`. | `RouteController`: `desc` → `description`. SPA RouteDetailView & RouteCreateView: send/read `description`. |
| **Queue** | API and SPA used `conf`; `queue` table has no `conf`. | Removed `conf` from `QueueController` updateableColumns. Removed conf field and payload from QueueDetailView and QueueCreateView. |
| **Ivr** | `ivrmenu.greetnum` is TEXT; API validated as integer. | `IvrController`: `greetnum` rule changed to `string\|nullable`. |

---

## Controller vs schema (by table)

- **Tenant (cluster):** Previously fixed (e.g. clusterclid string). All updateable columns exist in schema.
- **Extension (ipphone):** Previously fixed (devicerec default, no location/provision/provisionwith/sndcreds). See `XREF_EXTENSION_IPPHONE_SCHEMA.md`.
- **Trunk (trunks):** All updateable columns exist. sipiaxpeer/sipiaxuser removed from validation; not in schema.
- **Route (route):** Uses `description` (not `desc`). path1–4, active, auth, cluster, dialplan, strategy exist.
- **Queue (queue):** No `conf`. cluster, devicerec, greetnum, options exist.
- **Agent (agent):** cluster, name, passwd, queue1–6 all exist. (Schema has deprecated `name`; `cname` exists.)
- **Ivr (ivrmenu):** All listed columns exist. greetnum type aligned to string.
- **InboundRoute (inroutes):** All updateable columns exist in schema.

---

## SPA panels checked

- **Route:** Detail and Create use `description` only (no `desc`).
- **Queue:** Detail and Create no longer send or show `conf`.
- **Trunk:** No sipiaxpeer/sipiaxuser in UI.
- **Extension, Tenant:** Already aligned in prior session.

---

## Optional follow-ups (not required for compliance)

- **Types:** Some columns are TEXT in schema but validated as integer (e.g. tenant `emergency`, trunk `callerid`). Can be relaxed to string/regex if leading zeros or spaces matter.
- **Agent:** Schema deprecates `name` in favour of `cname`; API still uses `name`. Can add `cname` later.
