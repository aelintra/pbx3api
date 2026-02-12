# Cross-reference: Extension (ipphone) schema vs API and model

**Source of truth for actual DB:** **pbx3spa/running_schema.sql** (dump from the running database).  
**Also aligned with:** pbx3/full_schema.sql and pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_tenant.sql — ipphone column set matches.  
Purpose: align API validation and Extension model with schema; diagnose "Undefined array key 29".

---

## 1. ipphone columns in schema order (0-based index)

| Index | Column       | Type     | full_schema.sql | Notes |
|-------|--------------|----------|-----------------|--------|
| 0     | id           | TEXT     | ✓               | PK, KSUID |
| 1     | shortuid     | TEXT     | ✓               | UNIQUE, 8-char |
| 2     | pkey         | TEXT     | ✓               | Extension number |
| 3     | abstimeout   | INTEGER  | ✓               | Default 1440 |
| 4     | active       | TEXT     | ✓               | Default 'YES' |
| 5     | basemacaddr  | TEXT     | ✓               | not used |
| 6     | callerid     | TEXT     | ✓               | CLID (diallable) |
| 7     | callbackto   | INTEGER  | ✓               | Default 100 (schema); API uses desk/cell |
| 8     | cname        | TEXT     | ✓               | common name |
| 9     | callmax      | INTEGER  | ✓               | Default 3 |
| 10    | cellphone    | TEXT     | ✓               | cell twin number |
| 11    | celltwin     | TEXT     | ✓               | on/off |
| 12    | cluster      | TEXT     | ✓               | Tenant, default 'default' |
| 13    | desc         | TEXT     | ✓               | SIP username (Asterisk) |
| 14    | description  | TEXT     | ✓               | |
| 15    | device       | TEXT     | ✓               | device vendor |
| 16    | devicemodel  | TEXT     | ✓               | Harvested |
| 17    | devicerec    | TEXT     | ✓               | Default 'default' |
| 18    | dvrvmail     | TEXT     | ✓               | mailbox |
| 19    | extalert     | TEXT     | ✓               | |
| 20    | macaddr      | TEXT     | ✓               | |
| 21    | passwd       | TEXT     | ✓               | Asterisk password |
| 22    | protocol     | TEXT     | ✓               | Default 'IPV4' |
| 23    | pjsipuser    | TEXT     | ✓               | |
| 24    | stealtime    | INTEGER  | ✓               | |
| 25    | stolen       | TEXT     | ✓               | |
| 26    | technology   | TEXT     | ✓               | SIP/IAX2/… |
| 27    | tls          | TEXT     | ✓               | |
| 28    | transport    | TEXT     | ✓               | Default 'udp' |
| **29**| **vmailfwd** | **TEXT** | ✓               | **→ "Undefined array key 29" = 30th column** (confirmed in pbx3spa/running_schema.sql) |
| 30    | z_created    | datetime | ✓               | |
| 31    | z_updated    | datetime | ✓               | |
| 32    | z_updater    | TEXT     | ✓               | Default 'system' |

So if any code builds an array of ipphone columns (or first 30 columns) with 0-based indices and accesses `[29]`, that key is **vmailfwd**. An "Undefined array key 29" would mean that array has only 29 elements (indices 0–28) and is missing the 30th.

---

## 2. ExtensionController::$updateableColumns (API update payload)

| Column       | Validation rule                    | In schema? | Schema type | Note |
|--------------|------------------------------------|------------|-------------|------|
| active       | in:YES,NO                          | ✓          | TEXT        | OK |
| callbackto   | in:desk,cell                       | ✓          | INTEGER     | **Mismatch**: schema INTEGER 100; API string desk/cell |
| callerid     | integer\|nullable                  | ✓          | TEXT        | **Mismatch**: schema TEXT (CLID); API integer → lose leading zeros |
| cellphone    | integer\|nullable                  | ✓          | TEXT        | **Mismatch**: schema TEXT; API integer |
| celltwin     | in:ON,OFF                          | ✓          | TEXT        | OK |
| cluster      | exists:cluster,pkey                | ✓          | TEXT        | OK |
| desc         | nullable\|string\|max:255          | ✓          | TEXT        | OK |
| devicerec    | in:None,OTR,…                      | ✓          | TEXT        | OK |
| dvrvmail     | exists:ipphone,pkey\|nullable      | ✓          | TEXT        | OK |
| location     | in:local,remote                    | ✗          | —           | **Not in full_schema ipphone** (may exist elsewhere) |
| protocol     | in:IPV4,IPV6                       | ✓          | TEXT        | OK |
| provision    | string\|nullable                   | ✗          | —           | **Not in full_schema ipphone** |
| provisionwith| in:IP,FQDN                         | ✗          | —           | **Not in full_schema ipphone**; model guarded |
| sndcreds     | in:No,Once,Always                  | ✗          | —           | **Not in full_schema ipphone**; model guarded |
| transport    | in:udp,tcp,tls,wss                 | ✓          | TEXT        | OK |
| vmailfwd     | email\|nullable                   | ✓          | TEXT        | OK |

Count: **16** updateable column names. If this array were re-indexed 0–15, there is no index 29; the "29" is not from this list.

---

## 3. ExtensionRequest::rules() (validation rules)

Same 16 field names as above, plus: **pkey**, **macaddr**, **device**, **location** (required in rules; device required). So more keys than updateableColumns, but still no 30th element that would be index 29. The unique rule uses the route parameter (we now pass pkey string, not model).

---

## 4. Extension model ($attributes, $guarded)

- **$attributes** (defaults): abstimeout, active, basemacaddr, callbackto, devicerec, cluster, protocol, transport, technology, z_updater (10 keys).
- **$guarded** (not mass-assignable): abstimeout, basemacaddr, devicemodel, dialstring, firstseen, lastseen, passwd, provisionwith, sndcreds, z_created, z_updated, newformat, openfirewall, stealtime, stolen, tls, twin (17 keys).  
  Some guarded names (e.g. dialstring, firstseen, lastseen, newformat, openfirewall, twin) are not in full_schema ipphone — may be legacy or from another schema.

Model has no `$fillable`; fillable = all columns not in `$guarded`. That set is still finite and not 30 elements in a way that would make index 29 special.

---

## 5. Conclusion: where "29" likely comes from

- **Schema column order**: The 30th column (0-based index **29**) in ipphone is **vmailfwd**. Any code that:
  - builds an array of ipphone columns (or first 30 columns) with numeric indices, and
  - accesses index 29  
  will reference **vmailfwd**. If that array were built with only 29 elements (0–28), `$arr[29]` would trigger "Undefined array key 29".
- The API controller and FormRequest use **associative** arrays (string keys); they don’t have a natural "30th" element. So the 29 likely comes from framework or DB layer code that operates on a 0-indexed list of table columns (e.g. schema introspection or validation internals).
- **Recommendation**: When debugging on the VM, capture the full stack trace for "Undefined array key 29" to see the exact file and line (and which array is used). The fix we applied (pass pkey string to unique rule; use `$request->all()` for JSON body) may avoid the code path that builds that array or may change its size.

---

## 6. Schema vs API type mismatches to fix (optional)

| Column     | Schema  | API rule              | Suggested API change |
|------------|--------|------------------------|----------------------|
| callerid   | TEXT   | integer\|nullable      | nullable\|string\|regex:/^\d*$/ (preserve leading zeros for CLID) |
| cellphone  | TEXT   | integer\|nullable      | nullable\|string\|regex:/^\d*$/ (same) |
| callbackto | INTEGER| in:desk,cell           | Keep as-is if app layer maps desk/cell ↔ integer; else align schema or API |

Columns **location**, **provision**, **provisionwith**, **sndcreds** are in the API but not in full_schema.sql ipphone; confirm whether they exist in the actual DB (e.g. migrations) or should be removed from updateableColumns.
