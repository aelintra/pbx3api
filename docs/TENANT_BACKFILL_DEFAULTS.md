# Tenant default backfill (artisan transformer)

**Command:** `php artisan tenant:backfill-defaults`

**Purpose:** One-time data fix after you add new `DEFAULT` values to the tenant schema. In SQLite, `DEFAULT` only applies to **new** rows; existing rows keep `NULL` in those columns until you update them. This command runs `UPDATE ... SET column = <default> WHERE column IS NULL` for each table/column we added defaults to, so existing data matches the new schema and GET /schemas returns correct defaults.

**When to run:**

- After deploying an updated tenant schema (from `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql`) to a server that already has tenant data (e.g. restored from backup or migrated from an older schema).
- Run **once per tenant database** after the schema is in place. Each Laravel/pbx3api instance uses one DB (see `.env` `DB_DATABASE`), so run the command once per instance after deploy.

**What it does:**

- Uses the default DB connection (same as the API).
- For each table/column in the backfill map, runs: `UPDATE table SET column = ? WHERE column IS NULL`.
- Skips tables or columns that don’t exist (logs a warning, continues).
- Prints how many rows were updated per table.column.

**Backfill map (table → column → value):**

| Table            | Column(s)                    | Value(s)                          |
|------------------|------------------------------|-----------------------------------|
| cluster          | masteroclo                   | AUTO                              |
| ipphone          | celltwin                     | OFF                               |
| ivrmenu          | cluster, timeout             | default, 30                       |
| inroutes         | cluster, openroute, closeroute | default, None, None            |
| queue            | cluster, devicerec           | default, None                     |
| route            | cluster                      | default                           |
| trunks           | cluster, openroute, closeroute, devicerec | default, None, None, default |
| cos              | cluster                      | default                           |
| ipphonecosopen   | cluster                      | default                           |
| ipphonecosclosed | cluster                      | default                           |
| page             | cluster                      | default                           |

**Implementation:** `App\Services\TenantDefaultBackfillService`. The map lives in that class; to add more columns after a future schema change, extend `$backfillMap` and run the command again.

**Example output:**

```
Backfilling NULL columns to new schema defaults...
  cluster.masteroclo: 5 row(s) updated
  ipphone.celltwin: 21 row(s) updated
  trunks.devicerec: 3 row(s) updated
  cos.cluster: 1 row(s) updated
  page.cluster: 1 row(s) updated
Done. 31 row(s) updated.
```

**See also:** GET /schemas (docs/SCHEMAS_ENDPOINT.md) reads defaults from the **schema** (PRAGMA). Backfilling only fixes **data**; to get new defaults in the schema itself, the tenant DB must be created or migrated from the updated `sqlite_create_tenant.sql`.
