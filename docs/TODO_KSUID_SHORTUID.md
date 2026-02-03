# ToDo: Centralise KSUID and shortuid generation

## Current state

Each save/create route that creates a row in a table with `id` (KSUID) and `shortuid` columns manually calls `generate_ksuid()` and `generate_shortuid()` in the controller before `$model->save()`. Examples: **IvrController**, **InboundRouteController**, **TrunkController**, **TenantController**; **ExtensionController** uses a different path but also generates them.

That duplicates the same two lines in every such controller and is easy to forget when adding a new resource.

## Why not middleware?

**Middleware is the wrong layer.** It runs in the HTTP pipeline and does not know which Eloquent model is being created or when `save()` is called. It has no access to the model instance to set `id` and `shortuid`. So middleware is not a good fit for “set these attributes when this entity is created.”

## Recommended approach: model layer (trait or boot)

**Do it once in the model layer** so that any create for a table that has `id` + `shortuid` gets them set automatically. That way:

- Controllers stay thin and never have to remember to set them.
- The rule “this table has KSUID + shortuid” lives next to the schema/model.
- New resources that use the same schema get the behaviour by reusing one implementation.

**Option A – Trait (recommended)**  
- Add a trait, e.g. `App\Models\Concerns\HasKsuidShortuid`.
- In the trait, register a **`creating`** (or **`saving`**) model event: if the model has `id` / `shortuid` and they are null or empty, set `id = generate_ksuid()` and `shortuid = generate_shortuid()`.
- Use the trait on every model whose table has those columns: **Ivr**, **InboundRoute**, **Trunk**, **Tenant**, and any others (e.g. **Extension** if it uses the same pattern).
- Controllers then stop setting `id` and `shortuid`; the trait runs once per create.

**Option B – Per-model boot**  
- Same logic (set `id` and `shortuid` in a `creating` callback when empty), but implemented in each model’s `booted()` or `boot()` instead of a trait. No new trait, but the same few lines repeated in each model.

**Option C – Observer**  
- Register an observer in a service provider for Ivr, InboundRoute, Trunk, Tenant, etc., and in the observer’s `creating` method set `id` and `shortuid` when empty. Keeps controllers and models clean but spreads the rule across an observer class and the provider registration.

**Recommendation:** Use **Option A (trait)** so the rule is defined once and each model opts in by using the trait. When implementing, remove the manual `generate_ksuid()` / `generate_shortuid()` calls from the affected controllers so creation is the single place that assigns these values.
