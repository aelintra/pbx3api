# API documentation – issues and inconsistencies

Review of **pbx3api/docs** (api.md, auth.md, general.md) against **routes/api.php** and controllers. One code bug was fixed; doc and route mismatches are listed for correction.

---

## Code bug (fixed)

- **routes/api.php line 132:** `CosOPenController` was a typo; the class is `CosOpenController`. Fixed.

---

## api.md

| Issue | Location | Fix |
|-------|----------|-----|
| Missing space in heading | Line 1: `#Overview` | Use `# Overview` for consistency with other headings. |

---

## auth.md

| Issue | Location | Fix |
|-------|----------|-----|
| Typo | "succesfully" | Use "successfully". |

---

## general.md – paths and methods (vs routes/api.php)

| Issue | Doc says | Actual route / note |
|-------|----------|----------------------|
| Daytimers path | `GET /daytimer/{daytimer?}` (singular) | Routes use **daytimers** (plural): `GET /daytimers`, `GET /daytimers/{daytimer}`. |
| cosopens POST | `POST /cosopenes/{cosopen}` | Typo: **cosopenes** → **cosopens**. Create is `POST /cosopens` (no id in path). |
| coscloses POST | `POST /coscloses/{cosclose}` | Create is `POST /coscloses` (no id in path). |
| cosrules POST | `POST /cosrules/{cosrule}` | Create is `POST /cosrules` (no id in path). |
| DELETE coscloses/opens/rules | `DELETE/coscloses/...` (no space) | Use `DELETE /coscloses/...` etc. |
| Routes (ring groups) | `POST /route` (singular) | Route is `POST /routes`. |
| Logs CDR | `GET /logs/cdrs/{limit?}` (slash before limit) | Route is `logs/cdrs{limit}` (no slash; limit is a path suffix, e.g. `.../logs/cdrs50`). Doc and route disagree on URL shape. |
| System commands | List shows **pbxstart**, **pbxstop** | Routes are `syscommands/start`, `syscommands/stop` (no "pbx" prefix). |
| Templates | Full CRUD (GET/POST/PUT/DELETE) documented | No template routes in **api.php** – either not implemented or defined elsewhere. |

---

## general.md – request bodies and validators

| Issue | Location | Fix |
|-------|----------|-----|
| Custom Apps POST body | Lines 105–110 | Broken keys: `key'` → `'pkey'`, `luster'` → `'cluster'`, `esc'` → `'desc'`, `xtcode'` → `'extcode'`, and add missing leading quote for `span'` → `'span'`. |
| Routes POST body | Lines 487–490 | Use `=>` not `=` for array syntax. `'cluster' = 'required|...'` missing closing quote. `'outcome' = 'required;` is wrong: controller expects **pkey** and **cluster** (no "outcome"); fix or remove. |
| cosrules POST | Line 94 | `alpha_dashy` → **alpha_dash**. |
| AstDB GET | Line 825 | Doc: `DBPGet`; route: **DBget** (lowercase "get"). Align doc to route. |
| AstDB PUT | Line 826 | Doc: `DBPut/{id}/{key}(value)`; route: **DBput/{id}/{key}/{value}`. Use slash before value and lowercase "put". |
| originate body | Lines 819–822 | Doc uses `target = '...'` (single `=`, key not quoted). Use Laravel-style array: `'target' => 'required|numeric'`, etc. Controller has `context` without a rule and `clid` as optional; doc says clid required – align. |
| Sysglobals PLAYBEEP | Line 580 | `'in:YES.NO'` → **'in:YES,NO'** (comma, not dot). Same in **SysglobalController.php** if you want valid validation. |
| IVR PUT timeout | Line 419 | `'timeout' => 'operator'` – "operator" is not a standard Laravel rule (controller has same; may be custom rule or typo for integer/numeric). |
| Trunks PUT transform | Lines 758–761 | `':regex:...'` is invalid as array key; use string key **'regex:...'**. |
| Hangup example text | Line 834 | "perfoms" → **performs**. |

---

## Summary

- **Code:** One bug fixed in **api.php** (CosOPenController → CosOpenController).
- **Docs:** Paths (daytimers, cosopens/coscloses/cosrules, routes, logs/cdrs, syscommands), request bodies (Custom Apps, Routes POST, cosrules, AstDB, originate, sysglobals, IVR timeout, Trunks transform), and wording (auth, Hangup) should be updated as in the table for consistency with the implemented API.
