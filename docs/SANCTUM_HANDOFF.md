# Sanctum / Auth Handoff – Context for Next Chat

**Last updated:** After completing Sanctum fixes, user-abilities migration, and ability lexicon. Use this to get up to speed quickly.

---

## Repos and roles

- **pbx3api** – Laravel 11 API; auth lives here (Sanctum, `config/abilities.php`, `AuthController`, `routes/api.php`, `users.abilities`).
- **pbx3-frontend** – Vue 3 SPA; uses Bearer token from login, sessionStorage, whoami for user/abilities. No auth logic beyond that.
- **pbx3** – PBX/schema/scripts; not involved in Sanctum.

---

## What was done in this chat

1. **Phase 1–4 (Sanctum):** Removed token logging, set token expiration, fixed `ValidateClusterAccess`, replaced manual `get_token_abilities()` with Sanctum `abilities:admin` middleware, removed `EnsureFrontendRequestsAreStateful`, added `Authenticate` middleware, standardized ability name to `admin`, improved token lifecycle (no delete-all on login, logout revokes current token only), whoami returns `abilities`.
2. **User abilities instead of role:** New `users.abilities` column (JSON array). Login and register use it for token abilities. Role column is no longer used for auth (may still exist in DB).
3. **Ability lexicon:** `config/abilities.php` – single source of truth for ability names; register validates `abilities` against it. Only `admin` is defined so far.
4. **Docs:** `docs/auth.md` updated (abilities, register response with `abilities`, whoami, logout behaviour). `config/abilities.php` comments explain ability string vs array and that **DB value must be JSON array** (e.g. `["admin"]`), not plain string `admin`.
5. **Login bug fix:** “Invalid ability provided” was due to token having no abilities (user had null/empty or plain string in `users.abilities`). Added `normalizeAbilities()` in AuthController so token always gets a flat array of strings; clarified in config that `users.abilities` must be stored as JSON array.

---

## Current auth model (short)

- **Source of truth for “what can this user do?”:** `users.abilities` (JSON array of strings, e.g. `["admin"]`). Stored in DB; cast to array on User model.
- **At login:** Token is created with `createToken($name, $user->abilities)`. Sanctum stores that list on the token.
- **Route protection:** `auth:sanctum` plus `abilities:admin` or `ability:admin,viewer` middleware. No role checks in code.
- **Lexicon:** `config/abilities.php` → key = ability name (string), value = description. Register only allows abilities from this list.
- **Critical:** In the DB, `users.abilities` must be valid JSON for an array (e.g. `["admin"]`). Plain string `admin` breaks the array cast and results in empty abilities → “Invalid ability provided” on admin routes.

---

## Key files (pbx3api)

| File | Purpose |
|------|--------|
| `config/abilities.php` | Ability lexicon; comments explain string vs array and JSON-in-DB requirement. |
| `config/sanctum.php` | Token expiration (env `SANCTUM_TOKEN_EXPIRATION`), stateful domains, etc. |
| `app/Http/Controllers/AuthController.php` | Login, register, whoami, logout; uses `normalizeAbilities()` before createToken. |
| `app/Models/User.php` | `abilities` in fillable, cast `'abilities' => 'array'`. No `role` in fillable. |
| `routes/api.php` | Auth routes; admin routes under `abilities:admin`; test route `GET test/admin-only`. |
| `app/Http/Middleware/ValidateClusterAccess.php` | Uses `$request->user('sanctum')->tokenCan('cluster:...')`. |
| `database/migrations/2025_02_06_000000_replace_user_role_with_abilities.php` | Adds `users.abilities` (JSON nullable). Does not touch `role`. |
| `docs/auth.md` | API auth docs: login, logout, register (with `abilities`), whoami, users table. |

---

## Common issues and fixes

- **“Invalid ability provided”**  
  Token has no required ability. Ensure `users.abilities` is a JSON array (e.g. `["admin"]`), not null or plain string. Run e.g. `UPDATE users SET abilities = '["admin"]' WHERE ...;` then log in again.
- **New user has no access**  
  Set `users.abilities` for that user (JSON array). Or create via register with body `"abilities": ["admin"]` (as an admin).
- **Adding a new ability**  
  Add it to `config/abilities.php` (key + description). Then use it in routes (e.g. `ability:admin,viewer`) and optionally assign in register / user management.

---

## Optional / not done

- **Phase 5 (frontend):** Ability-based UI (e.g. hide admin menu if no `admin` in whoami `abilities`). Whoami already returns `abilities`; frontend can use it when needed.
- **Role column:** Still in DB on some installs; unused by auth. Can be dropped or ignored.
- **Spatie or other packages:** Not used; abilities are lexicon + `users.abilities` + Sanctum token abilities only.

---

## Branch and commits (pbx3api)

Work is on branch **newpanels**. Recent commits include: Phase 1–4 Sanctum fixes, user abilities + lexicon + migration, register response + auth docs, normalizeAbilities + abilities config comment (DB must be JSON array).

Use this file plus `SANCTUM_ANALYSIS.md` (full analysis and stepwise plan) and `docs/auth.md` (API behaviour) for full picture.
