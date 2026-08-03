# Resources

Static URIs:

```php
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

->addResource(
    resource: new Resource(name: 'config', uri: 'config://app.toml', mimeType: 'application/toml'),
    reader: static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: file_get_contents('/etc/app.toml'))],
        ttlMs: 60_000,
        cacheScope: CacheScope::Public,
    ),
)
```

Binary contents ride `BlobResourceContents`, whose `blob` is the base64-encoded bytes:

```php
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;

reader: static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(
    contents: [new BlobResourceContents(uri: $uri, blob: base64_encode($bytes), mimeType: 'image/png')],
),
```

URI templates ([RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570)):

```php
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;

->addResourceTemplate(
    template: new ResourceTemplate(name: 'user', uriTemplate: 'users://{userId}'),
    reader: static fn(string $uri, array $vars, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: loadUser($vars['userId']))],
        ttlMs: 0,
        cacheScope: CacheScope::Private,
    ),
)
```

`$vars` carries the resolved template variables (`['userId' => '123']` for `users://123`). The reader can
be a `\Closure` or a `ResourceReaderInterface` / `TemplatedResourceReaderInterface`.

## Cache hints

`ReadResourceResult` and the `*/list` results require two cache hints the server returns to the client:
`ttlMs` (how many milliseconds the client MAY treat the response as fresh, `0` meaning re-fetch every time)
and `cacheScope` (`CacheScope::Public` for a response any shared cache MAY serve to any user, or
`CacheScope::Private` for one only the requesting user's client MAY cache). Both default to `ttlMs: 0` and
`CacheScope::Private`. `setTtlMs()` and `setCacheScope()` change them for every store the builder assembles
from its `add*()` entries:

```php
->setTtlMs(60_000)
->setCacheScope(CacheScope::Public)
```

To vary them per feature, build that store yourself and pass it through the matching setter. A store
supplied that way keeps its own values and ignores the builder-level defaults:

```php
->setToolStore(new ToolStore($entries, ttlMs: 60_000, cacheScope: CacheScope::Public))
```

`$entries` is what `addTool()` would have built: a map of tool name to `ToolEntry`, each pairing a `Tool`
with its executor. `PromptEntry`, `ResourceEntry`, and `ResourceTemplateEntry` do the same for the other
stores. Building a store yourself is also how you hand the same instance to something else that needs it,
such as the `Mcp-Param-{Name}` validation on
[`SecuredHttpEndpoint`](../transports.md#securing-the-endpoint).

```php
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;

$entries = ['greet' => new ToolEntry($greetTool, new ClosureToolExecutor($greetExecutor))];
```
