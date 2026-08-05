# Apps

The client half of the MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) pairs
`AppsClientExtension`, which advertises the renderable mime types, with the `AppClient` facade,
which reads the `_meta.ui` metadata and verifies `ui://` reads:

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Extension\Apps\Client\AppClient;
use Nexus\Mcp\Extension\Apps\Client\AppsClientExtension;

$client = new ClientBuilder()
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new AppsClientExtension())
    ->build();

$client->connect($transport);
$client->discover();

$apps = new AppClient($client);
```

Enabling the extension stamps `{"mimeTypes": ["text/html;profile=mcp-app"]}` under
`io.modelcontextprotocol/ui` in the `_meta` capabilities envelope on every request, which is the
declaration a server consults before exposing its UI-enabled tools. The spec makes `mimeTypes`
required, so the constructor rejects an empty list, and a host that renders more than the
default profile passes the same list to both halves so the declaration and the verification
stay one decision: `new AppsClientExtension(mimeTypes: $types)` and
`new AppClient($client, mimeTypes: $types)`. The extension declares no methods of its own, so
nothing new is gated or dispatched.

## Finding UI-enabled tools

`resolveToolMeta()` decodes a tool's `_meta.ui` object into a typed `UiToolMeta`, and
`findAppTools()` filters a listing down to the tools that link a view, each paired with the
metadata the filter already resolved:

```php
$tools = $client->listTools();

foreach ($apps->findAppTools($tools) as $appTool) {
    // $appTool->tool, $appTool->uiMeta->resourceUri, $appTool->uiMeta->visibility
}
```

A tool whose `_meta.ui` cannot be decoded is skipped by the filter rather than aborting the
listing, since the unprefixed `ui` meta key is peer-controlled data. `resolveToolMeta()` on a
single tool stays strict and throws on malformed metadata. The reader tolerates the deprecated
flat `_meta["ui/resourceUri"]` key as a fallback when the nested form is absent or carries no
`resourceUri`, so servers that predate the nested shape (or migrated it partially) still
resolve. The SDK itself never emits the deprecated key.

## Reading the view

`readAppResource()` wraps `Client::readResource()` for `ui://` URIs: it rejects any other scheme
up front and verifies every returned content item carries one of the accepted mime types (the
`mimeTypes` the facade was constructed with, defaulting to `text/html;profile=mcp-app`),
throwing `InvalidUiResourceContentsException` when the server drifts. An `InputRequiredResult`
passes through untouched, like any other [input-required flow](input-required.md):

```php
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

$read = $apps->readAppResource('ui://weather-server/dashboard');

if ($read instanceof ReadResourceResult) {
    $meta = $apps->resolveResourceMeta($read->contents[0]);
    // $meta?->csp, $meta?->permissions, $meta?->domain, $meta?->prefersBorder
}
```

`resolveResourceMeta()` accepts both a `resources/list` descriptor and a read content item,
since the spec carries the same `UiResourceMeta` shape in both positions.

Rendering is out of the SDK's scope: hosting the HTML in a sandboxed iframe, enforcing the
declared CSP, and speaking the `ui/*` postMessage protocol to the embedded view are the browser
host's job, documented by the upstream `modelcontextprotocol/ext-apps` project.

The server half is documented in [Server apps](../server/apps.md).
