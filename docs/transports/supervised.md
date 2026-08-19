# SupervisedTransport

Restart supervision for a client transport whose peer can die underneath it. This page covers the
restart budget, the state that survives a respawn, and the opt-in request retry. The lifecycle both
ends honour is in [the transport contract](../transports.md#the-contract).

The decorator wraps a factory that mints one `SupervisableTransportInterface` per connection. It
respawns the peer when a connection ends without `close()` having been called. It is itself a
`TransportInterface`, so a `Client` connects to it exactly as it would to the transport it supervises.

```php
$transport = new SupervisedTransport(
    static fn(): SupervisableTransportInterface => new StdioClientTransport(['php', 'server.php']),
    maxRestarts: 3,
    restartDelay: 0.1,
    logger: $logger,
);

$client->connect($transport);
```

A factory is required rather than an instance because a transport is spent once its peer dies: the
line-framed duplex underneath it is single-use.

## Listeners

Register listeners once, on the `SupervisedTransport`. They survive every respawn: the decorator
re-binds them to each new connection, so nothing downstream is aware the peer changed.

## One close per connection

Each *connection* ends with one `onClose()` emission, so a supervised transport emits close more than
once over its life. That emission is what rejects the requests that were in flight when the peer died.
They are not retried. A `send()` issued between a death and its replacement is refused with
`TransportAlreadyClosedException`.

## The restart budget

The count is measured over a window. The window opens at the first restart and runs for
`restartWindow` seconds (60 by default). A death inside the window spends budget. The first death past
it opens a fresh window. The count therefore bounds a crash loop, not a healthy connection's lifetime.
The window is tumbling rather than sliding, so up to `2 * maxRestarts - 1` respawns can fall inside one
window-width span that straddles a boundary. When the budget is spent, the transport emits a
`SupervisionExhaustedException` through `onError()` and then closes for good.

Time comes from `microtime()` unless you pass a `clock` closure, which exists so a caller can make the
window boundary exact under test. The closure must read **seconds**. A source in other units
(`hrtime()` returns nanoseconds) makes every gap look larger than any window and silently leaves the
budget unspendable.

A served message deliberately does **not** clear the count. The protocol layer replays its own state on
every reconnect (see below), so even a peer that dies immediately is guaranteed to answer something.
Treating that answer as proof of health would make the budget unspendable.

## Intentional close

`close()` stops supervision permanently and cancels a pending respawn, so shutting down never races a
restart. When it cancels one, it emits a second `onClose()`: the connection's own close already went
out, and a caller holding state for the promised replacement needs to hear that it withdrew. An
explicit close drains the live connection before its close goes out. A close before `start()` still
fires `onDrain` then `onClose` once.

## The reconnect signal

The decorator implements `ReconnectingTransportInterface`. Its `onReconnect()` fires once per
replacement that has started serving: after the close for the connection it replaces, and never for
the first connection. A protocol layer holding per-connection state rebuilds it there. `Client` uses it
to re-open every open `subscriptions/listen` stream (see
[Subscriptions across a restart](#subscriptions-across-a-restart)).

No fresh discovery is needed after a respawn. This protocol revision is sessionless: every request
carries its own identity and capabilities in `_meta`, so a fresh peer serves the next request without
further protocol setup.

## Subscriptions across a restart

A `subscriptions/listen` stream is the one piece of client state a fresh peer cannot infer, because the
server holds the filter and the client holds the handle. `Client` re-sends the listen request for every
stream still open, **under the same subscription id**, as soon as the replacement is serving.

Reusing the id matters. The subscription id *is* the JSON-RPC id of the listen request, and the caller
holds it on `SubscriptionStream::$subscriptionId`. A fresh id would leave the caller naming a stream
the server has never heard of.

What the caller sees:

- The `SubscriptionStream` is not spent by a restart. Its callback keeps receiving notifications, and
  `await()` does not settle on the peer loss: it resumes against the replacement.
- **A peer that answers still ends the stream.** Only a failure a replacement will be given another go
  at is absorbed. A server that refuses the subscription has answered it, so the refusal reaches
  `await()` as `RemoteCallFailedException` and the stream is not replayed. `Client` tells the two apart
  by asking `ReconnectingTransportInterface::isReconnecting()` at the moment the request fails, not by
  the transport's type: a live connection has no replacement pending, so its failures are answers.
- A re-open the replacement cannot take is logged and left registered, so the peer after it tries
  again.
- Spending the restart budget fails every open stream with `SupervisionExhaustedException`, since no
  further peer is coming. `Client::disconnect()` fails them with `TransportAlreadyClosedException`, and
  so does closing the transport directly while a replacement is pending.
- A stream the caller closed before the restart is not restored.
- A delivery shed at the client's
  [in-flight dispatch cap](../client/configuration.md#in-flight-dispatch-cap) ends the stream on any
  transport, restart or none, with `await()` throwing `SubscriptionDeliveryDroppedException`.
- A reconnect listener that throws is reported through `onError()` and the rest of the chain still
  runs, so one consumer's failure cannot strand another's streams.

## Retrying a lost request

Off by default, and opt-in per client:

```php
$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->setRetryLostRequests(true)
    ->build();
```

With the flag on, a request whose peer died before answering is sent again to the replacement, under
its original id and carrying its original `SendContext`. The awaiting caller never learns the peer
changed.

**Only state-reading requests are eligible**, and the set is fixed: `server/discover`, `tools/list`,
`prompts/list`, `prompts/get`, `resources/list`, `resources/templates/list`, `resources/read` and
`completion/complete`. A retry is at-least-once, because the peer may have carried the work out and
died before the answer got back, and the spec marks no tool as idempotent. So `tools/call` is never
retried, however harmless a given tool is. Neither is a vendor method sent through `sendRequest()`,
whose semantics the SDK cannot judge. Everything outside the set fails on peer loss exactly as it does
with the flag off.

An MCP multi-round-trip continuation is excluded even though it names an eligible method. It carries
the user's answers plus an opaque resume token. Sending it again would hand a one-time answer over
twice and resume work the dead peer's token named.

A retried request keeps its original deadline. The restart eats into the same budget, so with a request
timeout configured, a peer that dies repeatedly still ends in `RequestTimeoutException` rather than
retrying forever. Set both `setRequestTimeout(null)` and `setMaxRequestTimeout(null)` and nothing
bounds it but the restart budget. Spending that budget fails the request with
`SupervisionExhaustedException`, and `Client::disconnect()` with `TransportAlreadyClosedException`. A
replacement that cannot take the re-send is logged and the request is left retained, so the peer after
it tries again.

Two limits are worth knowing before reaching for the flag:

- **Client-side caches are not invalidated.** A respawn reaches `Client` as a close, not as a
  `disconnect()`, so the `serverInfo`, `serverCapabilities` and parameter-header bindings recorded from
  the previous peer survive into the replacement. That is correct when the same command comes back
  serving the same thing, and stale when a peer restarts with a different capability set.
- **Capability interfaces are not forwarded.** The decorator implements `TransportInterface` and
  `ReconnectingTransportInterface` only. It is not a `ParameterHeaderMirroringInterface` or a
  `CancellableTransportInterface`, so wrapping a transport that implements one of those hides it from
  the `instanceof` checks in `Client` and `Server`. Supervise transports whose capabilities you do not
  depend on.
