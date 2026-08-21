# InMemoryTransport

An in-process transport pair for tests, with no I/O underneath. Each side delivers the other side's `send()`
calls as its own `onMessage` events, so an end-to-end server test runs without a subprocess. The lifecycle the
pair shares with the production bindings is in [the transport contract](../transports.md#the-contract).

```php
use Nexus\Mcp\Core\Transport\InMemoryTransport;

[$serverSide, $clientSide] = InMemoryTransport::createPair();
```

## Pre-`start()` inbound queueing

An envelope sent to a side that has not yet called `start()` is queued. The queue drains in arrival order the
moment that side starts. A test can therefore pre-load the full request sequence before `Server::run()` registers
its listeners and calls `start()` on the server-side transport:

```php
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\ServerBuilder;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

// Capture every server response delivered to the client side.
$received = [];
$clientSide->onMessage(static function (array $envelope) use (&$received): void {
    $received[] = $envelope;
});

// Start the client and pre-load a request. `$serverSide` is still Idle.
// Every $clientSide->send() call here queues onto `$serverSide`'s pendingInbound list.
$clientSide->start();

$meta = new RequestMetaObject(
    protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
    clientInfo: new Implementation(name: 'client', version: '1.0.0'),
    clientCapabilities: new ClientCapabilities(),
);
$clientSide->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: $meta)));

// Run the server. `Server::run()` calls `$serverSide->start()`, which drains the queue
// in arrival order into the dispatcher's onMessage listener.
$server = (new ServerBuilder())->setServerInfo(name: 'test', version: '0.1.0')->build();
$serverRun = \Amp\async(static fn() => $server->run($serverSide));

// Close to let run() return. close() cascades to the peer.
$clientSide->close();
$serverRun->await();
```

Without the pre-start queue, the test would have to interleave each emission with the server's setup, or risk
sending into a transport whose listener chain is not attached yet.

## Lifecycle cascade

`close()` cascades to the peer. Closing one side closes the other. On each side, the drain listener chain fires
first, then the close listener chain:

```php
$serverSide->close();
// Order of effects:
//   1. $serverSide drainListeners fire (server dispatcher awaits pending coroutines).
//   2. $serverSide transitions to Closed.
//   3. $clientSide->close() is invoked recursively.
//   4. $clientSide drainListeners fire.
//   5. $clientSide transitions to Closed.
//   6. $clientSide closeListeners fire.
//   7. $serverSide closeListeners fire.
```

## Send / start ordering errors

The state machine rejects out-of-order operations with typed exceptions, so a setup mistake surfaces early rather
than as silently dropped envelopes:

| Operation | When it throws | Exception |
| --- | --- | --- |
| `send()` | Called before `start()` | `TransportNotStartedException` |
| `send()` | Called after `close()` | `TransportAlreadyClosedException` |
| `start()` | Called twice | `TransportAlreadyStartedException` |
| `start()` | Called after `close()` | `TransportAlreadyClosedException` |

## `onError`

`onError` fires only for faults thrown by this side's own message listeners. There is no I/O failure surface for
an in-process pair. A listener fault stays on the receiving side rather than surface through the peer's `send()`.
