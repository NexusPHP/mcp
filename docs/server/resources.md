# Resources

How to expose resources. A static URI pairs a spec `Resource` with a reader through `addResource()`. An RFC 6570
template pairs a `ResourceTemplate` with a reader through `addResourceTemplate()`.

A static URI:

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

`$vars` carries the resolved template variables, for example `['userId' => '123']` for `users://123`. The reader
is a `\Closure`, a `ResourceReaderInterface`, or a `TemplatedResourceReaderInterface`.

## Template matching

When several templates match a URI, the one with the most literal characters answers. An exact `db://literal`
beats `db://{table}` regardless of registration order. Equally specific templates keep registration order.

A variable matches one URI segment and is percent-decoded after matching. The SDK refuses a value that decodes
back out of that segment: a `/`, `?`, `#`, a bare `.` or `..`, or a NUL byte. So `users://%2E%2E%2Fadmin` does not
match. That is the URI-template half of the resources spec's security requirement: a server MUST prevent
directory traversal attacks when it serves `file://` resources.

Inside the segment the value is still whatever the peer sent, so the other half is yours. Validate the value
against what you are about to open, index, or query.

## Attribute sugar

`#[AsResource]` and `#[AsResourceTemplate]` mark reader methods. The same
[`ServerBuilder::register()`](../attribute-discovery.md) walk discovers them as the other attributes. A `$uri`
parameter receives the resolved URI. Template variables are bound to parameters by name, so a variable named
`uri` is refused at registration, since `$uri` is taken. A `ServerContext` parameter is injected:

```php
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;

final class AppResources
{
    #[AsResource(uri: 'config://app.toml', mimeType: 'application/toml')]
    public function config(string $uri): string
    {
        return file_get_contents('/etc/app.toml');
    }

    /**
     * @param string $userId The user id.
     */
    #[AsResourceTemplate(uriTemplate: 'users://{userId}')]
    public function user(string $uri, string $userId): string
    {
        return loadUser($userId);
    }
}
```

A string return is wrapped as `TextResourceContents` bound to the URI. A `ResourceContents`, a list of them, or a
full `ReadResourceResult` passes through. Adapted returns carry the conservative cache hints (`ttlMs: 0`,
`CacheScope::Private`), so return a `ReadResourceResult` to set your own. See
[Attribute discovery](../attribute-discovery.md) for the full binding rules.

## Cache hints

`ReadResourceResult` and the `*/list` results require two cache hints that the server returns to the client.
`ttlMs` is how many milliseconds the client MAY treat the response as fresh, where `0` means re-fetch every time.
`cacheScope` is `CacheScope::Public` for a response any shared cache MAY serve to any user, or
`CacheScope::Private` for one only the requesting user's client MAY cache.

Both default to `ttlMs: 0` and `CacheScope::Private`. `setTtlMs()` and `setCacheScope()` change them for every
store the builder assembles from its `add*()` entries:

```php
->setTtlMs(60_000)
->setCacheScope(CacheScope::Public)
```

### Per-feature values

To vary the hints per feature, build that store yourself and pass it through the matching setter. A store
supplied that way keeps its own values and ignores the builder-level defaults:

```php
->setToolStore(new ToolStore($entries, ttlMs: 60_000, cacheScope: CacheScope::Public))
```

`$entries` is what `addTool()` would have built: a map of tool name to `ToolEntry`, each pairing a `Tool` with its
executor. `PromptEntry`, `ResourceEntry`, and `ResourceTemplateEntry` do the same for the other stores.

Building a store yourself is also how you hand the same instance to something else that needs it, such as the
`Mcp-Param-{Name}` validation on [`SecuredHttpEndpoint`](../transports/streamable-http.md#securing-the-endpoint).

```php
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;

$entries = ['greet' => new ToolEntry($greetTool, new ClosureToolExecutor($greetExecutor))];
```
