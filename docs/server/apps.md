# Apps

The MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) lets a tool link an interactive
HTML view that the host renders in a sandboxed iframe. The branding says "apps", but every
protocol literal says `ui`: the identifier is `io.modelcontextprotocol/ui`, the metadata rides
the `_meta` key `ui`, and the view is an ordinary MCP resource under the `ui://` scheme with the
`text/html;profile=mcp-app` mime type. It ships in `Nexus\Mcp\Extension\Apps` and, like every
extension, is disabled until enabled explicitly:

```php
use Nexus\Mcp\Extension\Apps\Schema\UiResourceCsp;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;
use Nexus\Mcp\Extension\Apps\Server\AppsServerExtension;
use Nexus\Mcp\Extension\Apps\Server\UiResource;
use Nexus\Mcp\Server\ServerBuilder;

$dashboard = new UiResource(
    name: 'dashboard',
    uri: 'ui://weather-server/dashboard',
    uiMeta: new UiResourceMeta(
        csp: new UiResourceCsp(connectDomains: ['https://api.openweathermap.org']),
        prefersBorder: true,
    ),
);

$server = new ServerBuilder()
    ->setServerInfo('demo', '1.0.0')
    ->enableExtension(new AppsServerExtension())
    ->addResource($dashboard->resource, static fn(): string => file_get_contents(__DIR__.'/dashboard.html'))
    ->addTool($weatherTool, $weatherExecutor)
    ->build();
```

The extension defines no JSON-RPC methods, so enabling it only advertises the
`io.modelcontextprotocol/ui` capability slot. Everything else is metadata on the tools and
resources the builder already registers.

## Declaring a UI resource

The spec binds a UI resource to three MUSTs: the URI starts with `ui://`, the mime type is
exactly `text/html;profile=mcp-app`, and the content is a valid HTML5 document. `UiResource`
composes a `Resource` that upholds the first two by construction, attaches the optional
`_meta.ui` metadata (kept readable on `$uiResource->uiMeta`), and exposes the result on
`$uiResource->resource` for `addResource()`. The HTML validity of what the reader returns stays
your responsibility, as does serving the same mime type and metadata on the read contents,
which reusing the composed `Resource`'s fields holds by construction:

```php
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Extension\Apps\Apps;

$contents = new TextResourceContents(
    uri: $dashboard->resource->uri,
    text: $html,
    mimeType: Apps::MIME_TYPE,
    meta: $dashboard->resource->meta,
);
```

`UiResourceMeta` carries the sandbox configuration the host enforces: the CSP allow-lists
(`UiResourceCsp`, where an empty list means the same as an omitted one), the requested
`UiResourcePermissions` (each encoded as a key with an empty-object value), the host-defined
dedicated `domain`, and the `prefersBorder` rendering hint. The spec puts the same shape on both
the `resources/list` descriptor and each `resources/read` content item, so declare it in both
places, as above.

## Linking a tool to its view

A tool opts in through the `_meta.ui` object, built with `UiToolMeta` and attached through the
`meta:` slot of manual or [attribute](../attribute-discovery.md) registration:

```php
use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;

#[AsTool(description: 'Returns the weather for a city.', meta: [
    'ui' => [
        'resourceUri' => 'ui://weather-server/dashboard',
        'visibility' => ['model', 'app'],
    ],
])]
public function weather(string $city): string { /* ... */ }
```

`UiToolMeta` validates the `ui://` scheme on `resourceUri`, and
`new UiToolMeta(resourceUri: ..., visibility: [ToolVisibility::App])->toArray()` produces the
same array for the manual path. An omitted `visibility` means the spec default
`["model", "app"]`, and the SDK never materialises the default on the envelope. The deprecated
flat `_meta["ui/resourceUri"]` key is never emitted.

## Capability direction

The negotiation is asymmetric. The client declares
`{"mimeTypes": ["text/html;profile=mcp-app"]}` under the extension slot, and the spec prescribes
no server-side settings, so `AppsServerExtension` advertises an empty object. The spec's guidance
that servers check client capabilities before exposing UI-enabled tools is per-request under
this revision: read `$context->meta->clientCapabilities->extensions['io.modelcontextprotocol/ui']`
inside a handler when you want to branch. The SDK deliberately does not filter `tools/list` by
that declaration: the tool metadata is inert for hosts that ignore it, the spec asks tools to
keep a text-only fallback anyway, and varying a cacheable listing per client would fight the
SEP-2549 cache semantics.

Everything under the `ui/*` postMessage family (`ui/initialize`, the host notifications, the
sandbox proxy) is the browser host's side of the extension and never touches the MCP connection,
so the SDK does not model it.

The client half is documented in [Client apps](../client/apps.md), and
[examples/apps-e2e/](../../examples/apps-e2e/) runs the whole flow, browser host included.
