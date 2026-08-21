# Stores and pagination

A per-feature store that the builder assembles backs every feature, and every store paginates its listings the
same way.

## Pagination

`CursorPaginator` paginates the `*/list` results, and each built-in store composes it. A store returns at most 50
entries per page. When more remain, it returns a `nextCursor` the client passes back to fetch the next page.
`setPageSize()` changes that for every store the builder assembles from its `add*()` entries:

```php
->setPageSize(200)
```

The size must be a positive integer. As with the cache hints, a store supplied through `setToolStore()` and its
siblings keeps its own page size.

## Custom stores

The in-memory stores that back tools, prompts, resources, and resource templates can be swapped for a custom
implementation. A setter replaces the store the builder would otherwise assemble from the matching `add*()`
entries, so registering a store also lights up that feature's capability.

```php
->setToolStore(new MyToolStore())                         // ToolStoreInterface
->setPromptStore(new MyPromptStore())                     // PromptStoreInterface
->setResourceStore(new MyResourceStore())                 // ResourceStoreInterface
->setResourceTemplateStore(new MyResourceTemplateStore()) // ResourceTemplateStoreInterface
```

### The store contracts

Each store implements the read surface its built-in handlers depend on: `list()` plus `call()`, `get()`, or
`read()`. The built-in in-memory stores go further. They implement the matching `Mutable*StoreInterface`, which
adds `add*()` and `remove*()` plus the `onListChanged()` seam from `ListChangeSourceInterface`.

A custom store may implement `ListChangeSourceInterface` alone when it can observe changes it does not itself
make, such as a database-backed listing. It stays a plain read surface when it cannot. `CompositeResourceStore`
forwards `onListChanged()` to whichever of its two inner stores reports changes.

When a custom store and the matching `add*()` entries are both supplied, the custom store wins and the SDK ignores
those entries.

### Resources and templates together

A custom resource store still composes with a resource template store, custom or entry-built, for
`resources/read`. In that composition, throw `ResourceNotRegisteredException` for a URI the store does not hold.
That is the one miss that falls through to templates.

A `ResourceNotFoundException` is authoritative. It ends the read without consulting templates, so an overlapping
template never answers a reader's deliberate refusal.
