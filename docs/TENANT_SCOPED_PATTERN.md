# Tenant-scoped panels: identity and uniqueness pattern

Resources that belong to a tenant (cluster) share the same pattern so that the same logical key (e.g. extension 1000) can exist in different tenants without cross-tenant updates or validation errors.

## Where it applies

- **API:** Extensions, Queues, Agents, Routes, Trunks, IVRs, Inbound routes. Also any future tenant-scoped resource (e.g. Custom apps).
- **Not:** Tenants (cluster table) themselves — they are top-level and already unique.

## Rule: two roles for two fields

| Role | Field | Use |
|------|--------|-----|
| **Identity** (which row) | `id` (KSUID) | Primary key. Route binding, UPDATE/DELETE. Prefer `id`; shortuid in URLs is optional and resolves to one row. |
| **Uniqueness / display** | `pkey` + `cluster` | Uniqueness is *per cluster*. Same pkey in different clusters is allowed. |

## Model

- `primaryKey = 'id'`
- `$keyType = 'string'`, `$incrementing = false`
- `resolveRouteBinding($value)`: resolve by shortuid (exact, then case-insensitive), then `id`, then pkey fallback so URLs can use shortuid or id.

## Controller create

**REQUIRED:** Set `id` (KSUID) and `shortuid` before `$model->save()`. The `id` field is the PRIMARY KEY and must be set for updates to work. Without it, `update()` will fail because `$model->id` will be null.

```php
move_request_to_model($request, $model, $this->updateableColumns);

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
2. **Update, pkey changed, or Create** — `Rule::unique('table', 'pkey')->where('cluster', $cluster)`; on update add `->ignore($model->getKey(), 'id')`.

Example (pattern used in ExtensionRequest, TrunkRequest):

```php
$resource = $this->route('resource'); // e.g. extension, trunk
$pkeySubmitted = $this->input('pkey');
$pkeyUnchanged = $resource instanceof \App\Models\Resource
    && (string) $pkeySubmitted === (string) $resource->getAttribute('pkey');

if ($pkeyUnchanged) {
    $pkeyRule = 'required';
} else {
    $cluster = $this->input('cluster');
    $pkeyRule = Rule::unique('table', 'pkey')->where('cluster', $cluster);
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
