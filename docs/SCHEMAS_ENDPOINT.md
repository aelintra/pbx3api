# GET /schemas

**Purpose:** Expose schema metadata for admin panels so the frontend can derive read-only vs editable fields and default values for create forms from a single source of truth. No duplicated metadata in the SPA.

**Auth:** Same as other admin read endpoints: `auth:sanctum` + `abilities:admin`.

**Response:** JSON object keyed by resource name. Each resource has:

| Key          | Description |
|--------------|-------------|
| `read_only`  | Column names that must not be edited (all table columns minus updateable; `id` and `shortuid` always included). |
| `updateable` | Column names that appear in the controller’s updateable list (allowed on update). |
| `defaults`   | Map of column name → default value from the database (for create forms). |

**Source of truth:**

- Column list and defaults come from the **running database** at request time (`PRAGMA table_info`). No cache, no file.
- Updateable list comes from each controller’s `getUpdateableColumns()` (backed by `$updateableColumns`).

**Resources:** extensions, queues, agents, routes, trunks, ivrs, inroutes, tenants.

**Errors:** If a table or controller fails for one resource, that resource is still returned with empty arrays; the whole response is not failed. See `App\Services\SchemaService` and `pbx3spa/workingdocs/FIELD_MUTABILITY_API_PLAN.md` for rationale and implementation details.
