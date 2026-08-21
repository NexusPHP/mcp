# Apps

The client half of the MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) pairs two classes.
`AppsClientExtension` advertises the renderable mime types. The `AppClient` facade reads the `_meta.ui` metadata
and verifies `ui://` reads:

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Extension\Apps\Client\AppClient;
use Nexus\Mcp\Extension\Apps\Client\AppsClientExtension;

$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new AppsClientExtension())
    ->build();

$client->connect($transport);
$client->discover();

$apps = new AppClient($client);
```

Enabling the extension stamps `{"mimeTypes": ["text/html;profile=mcp-app"]}` under `io.modelcontextprotocol/ui`
in the `_meta` capabilities envelope on every request. That is the declaration a server consults before it
exposes its UI-enabled tools.

The spec makes `mimeTypes` required, so the constructor rejects an empty list. A host that renders more than the
default profile passes the same list to both halves, so the declaration and the verification stay one decision:
`new AppsClientExtension(mimeTypes: $types)` and `new AppClient($client, mimeTypes: $types)`. The extension
declares no methods of its own, so nothing new is gated or dispatched.

## Finding UI-enabled tools

`resolveToolMeta()` decodes a tool's `_meta.ui` object into a typed `UiToolMeta`. `findAppTools()` filters a
listing down to the tools that link a view, each paired with the metadata the filter already resolved:

```php
$tools = $client->listTools();

foreach ($apps->findAppTools($tools) as $appTool) {
    // $appTool->tool, $appTool->uiMeta->resourceUri, $appTool->uiMeta->visibility
}
```

The filter skips a tool whose `_meta.ui` cannot be decoded rather than abort the listing, since the unprefixed
`ui` meta key is peer-controlled data. `resolveToolMeta()` on a single tool stays strict and throws on malformed
metadata.

The reader tolerates the deprecated flat `_meta["ui/resourceUri"]` key as a fallback when the nested form is
absent or carries no `resourceUri`. Servers that predate the nested shape, or migrated it partially, still
resolve. The SDK itself never emits the deprecated key.

## Reading the view

`readAppResource()` wraps `Client::readResource()` for `ui://` URIs. It rejects any other scheme up front. It
verifies that every returned content item carries one of the accepted mime types, which are the `mimeTypes` the
facade was constructed with, defaulting to `text/html;profile=mcp-app`. It throws `RuntimeException` when the
server drifts. An `InputRequiredResult` passes through untouched, like any other
[input-required flow](input-required.md):

```php
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

$read = $apps->readAppResource('ui://weather-server/dashboard');

if ($read instanceof ReadResourceResult) {
    $meta = $apps->resolveResourceMeta($read->contents[0]);
    // $meta?->csp, $meta?->permissions, $meta?->domain, $meta?->prefersBorder
}
```

`resolveResourceMeta()` accepts both a `resources/list` descriptor and a read content item, since the spec carries
the same `UiResourceMeta` shape in both positions.

Rendering is out of the SDK's scope. Hosting the HTML in a sandboxed iframe, enforcing the declared CSP, and
speaking the `ui/*` postMessage protocol to the embedded view are the browser host's job. The upstream
`modelcontextprotocol/ext-apps` project documents them.

The server half is documented in [Server apps](../server/apps.md), and
[examples/apps-e2e/](../../examples/apps-e2e/) runs the whole flow, browser host included.
