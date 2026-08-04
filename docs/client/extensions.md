# Extensions

Extensions (SEP-2133) are disabled by default on the client too: enabling one advertises its
capability identifier in the `_meta` `io.modelcontextprotocol/clientCapabilities` envelope stamped
onto every request, registers its inbound handlers, and gates its outbound methods against the
server's advertised capabilities.

```php
use Nexus\Mcp\Client\ClientBuilder;

$client = new ClientBuilder()
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new AcmeSnapshotClientExtension())
    ->build();
```

## Declaring an extension

A client extension implements `ClientExtensionInterface`: the same closed surface as the server
side (identifier, settings, inbound request/notification classes and handlers, all validated at
enable-time), bound to `ClientContext`, plus one client-only declaration:

- `getOutboundRequests()`: the client-to-server methods the extension invokes. After
  `discover()`, sending one of these to a server that did not advertise the extension throws
  `ServerCapabilityNotSupportedException` instead of a doomed round trip. Before discovery the
  send passes ungated, mirroring how the core capability gate behaves. An outbound method may not
  name a spec method or one another extension already claimed.

The extension's inbound request handlers are gated the same way in the other direction: once
`discover()` has run, an extension-owned request from a server that did not advertise the
extension is answered `-32601` and the handler never runs. Before discovery the request is served,
since there is nothing to check against. Notifications stay ungated on both sides.

## Capabilities merge

Enabled extensions merge into whatever `setClientCapabilities()` declared, in either call order.
The same identifier declared both manually and via `enableExtension()` is refused at `build()`
with `DuplicateExtensionException`: there is no silent precedence.

## Sending extension requests

Outbound extension calls ride [`sendRequest()`](requests.md#the-escape-hatch-sendrequest). Build
the params with `$client->stampMeta()` so the request carries the lifecycle `_meta` fields,
including the advertised extensions the server-side gate checks:

```php
$response = $client->sendRequest(
    new AcmeSnapshotRequest(
        id: new RequestId(id: 41),
        params: new AcmeSnapshotRequestParams(meta: $client->stampMeta()),
    ),
    AcmeSnapshotResultResponse::class,
);
```
