# Tenant-scoped panels: identity and uniqueness pattern

Resources that belong to a tenant (cluster) share the same pattern so that the same logical key (e.g. extension 1000) can exist in different tenants without cross-tenant updates or validation errors.

## Where it applies

- **API:** Extensions, Queues, Agents, Routes, Trunks, IVRs, Inbound routes. Also any future tenant-scoped resource (e.g. Custom apps).
- **Not:** Tenants (cluster table) themselves — they are top-level and already unique.

## Rule: store cluster shortuid, not pkey

**The `cluster` column in all tenant-scoped tables must store the cluster (tenant) *shortuid*, not the cluster pkey.**

- **Why:** Cluster pkey is human-provided and can be duplicated across systems. Shortuid is system-generated and unique, so it is used internally for referential integrity (RI) and uniqueness.
- **API receive:** The client sends the tenant identifier (cluster pkey) for display/selection. The API must resolve it to cluster shortuid and store the shortuid.
- **API return:** When returning resources, resolve the stored shortuid (or id) to cluster pkey for display as `tenant_pkey`.
- **Helper:** Use `cluster_identifier_to_shortuid($value)` (in `app/Helpers/Helper.php`) to resolve pkey, shortuid, or id to the shortuid to store. Validate with `exists:cluster,pkey` (or resolve and then validate) so the client can continue to send pkey.

## Rule: two roles for two fields

| Role | Field | Use |
|------|--------|-----|
| **Identity** (which row) | `id` (KSUID) | Primary key. Route binding, UPDATE/DELETE. Prefer `id`; shortuid in URLs is optional and resolves to one row. |
| **Uniqueness / display** | `pkey` + `cluster` | Uniqueness is *per cluster*. Same pkey in different clusters is allowed. The `cluster` column stores **cluster shortuid** (not pkey). |

## Model

- `primaryKey = 'id'`
- `$keyType = 'string'`, `$incrementing = false`
- `resolveRouteBinding($value)`: resolve by shortuid (exact, then case-insensitive), then `id`, then pkey fallback so URLs can use shortuid or id.

**Mass assignment (required):** **MUST** use **`$fillable`** (whitelist). **Do not use `$guarded`.** When converting or auditing a tenant-scoped model, replace any `$guarded` with a `$fillable` list. List every column that may be set from the API (all table columns except `id`, `shortuid` — set on create — and `z_*` — system-only; omit display-only/fixed columns from the list). New columns added to the table are then not mass-assignable until explicitly added to `$fillable`, which is safer.

**Serialization:** Use **`$hidden`** only for attributes that must not appear in array/JSON output. If the table has no such fields, use `protected $hidden = [];`. Other tables may have redundant or sensitive fields to hide; set `$hidden` per resource as we audit.

## Controller create

**REQUIRED:** Set `id` (KSUID) and `shortuid` before `$model->save()`. The `id` field is the PRIMARY KEY and must be set for updates to work. Without it, `update()` will fail because `$model->id` will be null.

**REQUIRED:** Resolve request `cluster` (pkey) to shortuid and set `$model->cluster = cluster_identifier_to_shortuid($request->cluster)` after `move_request_to_model`, so the DB stores shortuid not pkey. Use the same shortuid in any duplicate (pkey+cluster) check.

```php
move_request_to_model($request, $model, $this->updateableColumns);
$model->cluster = cluster_identifier_to_shortuid($request->cluster); // store shortuid, not pkey

// REQUIRED: Set id and shortuid before save
$model->id = generate_ksuid();
$model->shortuid = generate_shortuid();

try {
    $model->save();
} catch (\Exception $e) {
    return Response::json(['Error' => $e->getMessage()], 409);
}
```

**Note:** This is currently manual in each controller. See `TODO_KSUID_SHORTUID.md` for a plan to centralize this via a trait/model event.

## Controller update

After `move_request_to_model`, if `cluster` was in the request, set `$model->cluster = cluster_identifier_to_shortuid($request->cluster)` so the DB stores shortuid not pkey.

Do **not** rely on `$model->save()` for updates. Use an explicit update by `id` so only the resolved row is updated:

```php
if ($model->isDirty()) {
    $id = $model->id;
    if ($id === null || $id === '') {
        return Response::json(['Error' => 'Resource id is missing'], 409);
    }
    $dirty = $model->getDirty();
    Model::where('id', $id)->update($dirty);
    $model->syncOriginal();
}
```

## Form Request (pkey uniqueness)

When validating `pkey` (e.g. in a Form Request):

1. **Update, pkey unchanged** — skip unique check (only `required`). Avoids 422 when only other fields (e.g. Active) change.
2. **Update, pkey changed, or Create** — resolve client’s cluster (pkey) to shortuid with `cluster_identifier_to_shortuid($this->input('cluster'))`, then `Rule::unique('table', 'pkey')->where('cluster', $clusterShortuid)`; on update add `->ignore($model->getKey(), 'id')`. The DB stores shortuid, so the unique check must use shortuid.

Example (pattern used in ExtensionRequest, TrunkRequest):

```php
$resource = $this->route('resource'); // e.g. extension, trunk
$pkeySubmitted = $this->input('pkey');
$pkeyUnchanged = $resource instanceof \App\Models\Resource
    && (string) $pkeySubmitted === (string) $resource->getAttribute('pkey');

if ($pkeyUnchanged) {
    $pkeyRule = 'required';
} else {
    // DB stores cluster shortuid; unique check must use shortuid
    $clusterShortuid = cluster_identifier_to_shortuid($this->input('cluster'));
    $pkeyRule = Rule::unique('table', 'pkey')->where('cluster', $clusterShortuid ?? $this->input('cluster'));
    if ($resource instanceof \App\Models\Resource) {
        $pkeyRule->ignore($resource->getKey(), 'id');
    }
}
return [ 'pkey' => ['required', $pkeyRule], ... ];
```

## Reference implementations

- **Model:** `App\Models\Extension`, `Queue`, `Agent`, `Route`, `Trunk`, `Ivr`, `InboundRoute`
- **Controller create:** `TrunkController::save()`, `IvrController::save()`, `InboundRouteController::save()`, `TenantController::save()`, `CustomAppController::save()`
- **Controller update:** `ExtensionController::update()`, `QueueController::update()`, etc.
- **Form Request:** `ExtensionRequest`, `TrunkRequest`
