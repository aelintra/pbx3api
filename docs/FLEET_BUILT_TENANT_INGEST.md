# Built-tenant ingest (CLI)

**Lock:** [`pbx3/workingdocs/FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md`](../../pbx3/workingdocs/FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md)

Ingest a **sark-to-pbx3 split-tenant** sqlite (one `cluster`) into this home. Strips `globals`/`trunks`; remints opaque `shortuid`/`id` on collision; always sets FQDN `{shortuid}.{apex}`; on fleet nodes normalizes that tenant’s `route.path*` → `Egress`.

```bash
sudo -u www-data php artisan tenant:ingest-built /path/to/flixton.db --dry-run
sudo -u www-data php artisan tenant:ingest-built /path/to/flixton.db
```

Then follow the printed enroll hint (catalog `register-tenant.sh` + Register on SBC + Commit). Do not leave a node-only tenant.
