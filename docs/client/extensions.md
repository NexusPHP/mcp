# Extensions

Extensions (SEP-2133) are disabled by default on the client too. Enabling one does three things. It advertises
the capability identifier in the `_meta` `io.modelcontextprotocol/clientCapabilities` envelope stamped onto every
request. It registers the extension's inbound handlers. It gates the extension's outbound methods against the
server's advertised capabilities.

```php
use Nexus\Mcp\Client\ClientBuilder;

$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new AcmeSnapshotClientExtension())
    ->build();
```

## Declaring an extension

A client extension implements `ClientExtensionInterface`. It is the same closed surface as the server side
(identifier, settings, inbound request and notification classes and handlers, all validated at enable time),
bound to `ClientContext`, plus one client-only declaration.

`getOutboundRequests()` names the client-to-server methods the extension invokes. After `discover()`, sending one
of these to a server that did not advertise the extension throws `ServerCapabilityNotSupportedException` instead
of a doomed round trip. Before discovery the send passes ungated, which mirrors how the core capability gate
behaves. An outbound method may not name a spec method, or a method another extension already claimed.

### Inbound gating

The extension's inbound request handlers are gated the same way in the other direction. Once `discover()` has
run, an extension-owned request from a server that did not advertise the extension is answered `-32601`, and
the handler never runs. An extension-owned notification is dropped with a warning logged. Before discovery both
are served, since there is nothing to check against. Server-side, extension notifications stay ungated.

## Capabilities merge

Enabled extensions merge into whatever `setClientCapabilities()` declared, in either call order. The same
identifier declared both manually and through `enableExtension()` is refused at `build()` with `LogicException`.
There is no silent precedence.

## Sending extension requests

Outbound extension calls ride [`sendRequest()`](requests.md#the-escape-hatch-sendrequest). Build the params with
`$client->stampMeta()`, so the request carries the lifecycle `_meta` fields, including the advertised extensions
the server-side gate checks:

```php
$response = $client->sendRequest(
    new AcmeSnapshotRequest(
        id: new RequestId(id: 41),
        params: new AcmeSnapshotRequestParams(meta: $client->stampMeta()),
    ),
    AcmeSnapshotResultResponse::class,
);
```

## Official extensions

The [tasks extension](tasks.md) (`io.modelcontextprotocol/tasks`, SEP-2663) ships with the SDK and is the worked
example of this surface. `TasksClientExtension` declares the capability and the outbound `tasks/*` methods, and
the `TaskClient` facade wraps the `sendRequest()` calls.

The [apps extension](apps.md) (`io.modelcontextprotocol/ui`, SEP-1865) is the settings-only counterpart.
`AppsClientExtension` declares the renderable `mimeTypes` and no methods at all, and the `AppClient` facade only
reads metadata and verifies `ui://` resource reads.

The [OAuth extensions](../auth/extension-grants.md) go further still. Those are client credentials (SEP-1046) and
enterprise-managed authorization (SEP-990). They are settings-free declarations whose behaviour lives entirely at
the HTTP layer, as unattended grant strategies plugged into `AuthorizedHttpClient`.
