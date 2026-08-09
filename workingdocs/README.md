# workingdocs

**AI:** Product/SPA session state lives in **`~/GiT/pbx3-ops/`** (not the old SPA SESSION_HANDOFF body).

This **pbx3api** folder holds **validation / create-id** plans. Panel **audit prototypes** moved to **`~/GiT/pbx3-ops/devdocs/pbx3api/workingdocs/`** for public-clone cleanliness.

## Read order by task

| Task | Read (in order) |
|------|------------------|
| Model/validation alignment for a resource | PLAN_MODELS_AND_VALIDATION_HARMONISATION.md, then ops **`devdocs/.../{RESOURCE}_AUDIT_PROTOTYPE.md`** if needed |
| id/shortuid on create | CREATE_ID_SHORTUID_SCAN.md |

**Source of truth:** Schema and code (pbx3 db_sql, Laravel models/controllers). Verify against repo when changing behaviour; workingdocs may be outdated.
