# Built-tenant ingest (CLI)

**Lock:** [`pbx3/workingdocs/FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md`](../../pbx3/workingdocs/FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md)

Ingest an **external** one-tenant pbx3 sqlite (offline-migrate split, another fleet, etc.) into this home. Strips `globals`/`trunks`; remints opaque `shortuid`/`id` on collision; always sets FQDN `{shortuid}.{apex}`; on fleet nodes normalizes that tenant’s `route.path*` → `Egress`.

```bash
sudo -u www-data php artisan tenant:ingest-built /path/to/flixton.db --dry-run
sudo -u www-data php artisan tenant:ingest-built /path/to/flixton.db
```

Then follow the printed enroll hint:

1. Catalog `register-tenant.sh` (+ `pkey`/`label`)
2. Register on SBC (domain)
3. **Fleet → DIDs → Allocate** (hop-1) if PSTN is required — **not** done by this command
4. Commit + smoke

Do not leave a node-only tenant. See **`FLEET_DID_HOP1_LOCK.md`**.
