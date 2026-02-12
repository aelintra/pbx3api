# ToDo: IVR `name` field – research and decision

## Current state

The **ivrmenu** table has both:

- **`name`** (TEXT) – schema comment: *"name is deprecated and will be dropped in a near release use cname instead"*
- **`cname`** (TEXT) – *"common name"*

The API and frontend currently allow editing both **name** and **cname** (Display name) on IVR create and edit.

## Open question

**`name` likely shouldn’t exist** (or shouldn’t be exposed) but a decision is pending research:

- Confirm whether any code (API, frontend, Asterisk generator, other consumers) still depends on **name**.
- Decide whether to:
  - Remove **name** from the API and UI and rely only on **cname**, or
  - Keep **name** for backward compatibility until schema migration drops the column, or
  - Something else (e.g. hide from UI but keep in API for legacy).

## Action

- [ ] Research usage of **ivrmenu.name** across pbx3, pbx3api, pbx3spa, and any generators.
- [ ] Decide: remove from API/UI, keep for legacy, or other.
- [ ] If removing: drop from IvrController `updateableColumns`, Ivr model, and both IVR create/edit panels; optionally add DB migration to drop column when schema is updated.
