# Custom request and notification handlers

For vendor-extension methods (those outside the MCP spec), registration names the handler and the
envelope class that parses the method. The class is what makes the method parseable at all: the
message parser only recognises methods it has a class for, and answers `-32601` for the rest.

```php
->addRequestHandler('acme/lookup', new MyLookupHandler(), AcmeLookupRequest::class)
->addNotificationHandler('acme/heartbeat', new MyHeartbeatHandler(), AcmeHeartbeatNotification::class)
```

`AcmeLookupRequest` extends `JsonRpcRequest`, returns `'acme/lookup'` from `getMethod()`, and
implements the `ClientRequest` marker: registration rejects a class declaring a different method
or lacking the marker, since the dispatcher only serves `ClientRequest` requests. Its params must
be `RequestParams`-typed, carrying the lifecycle `_meta` every request is gated on, or the
dispatcher answers `-32600`. Notification classes extend `JsonRpcNotification` the same way,
without the marker.

Both reject spec-reserved methods. To override the SDK's built-in handler for a spec method
(e.g. to take over `tools/list`), use the `replace*` variants:

```php
->replaceRequestHandler('tools/list', new MyListToolsHandler())
->replaceNotificationHandler('notifications/cancelled', new MyCancelledHandler())
```

The `replace*` variants in turn reject non-spec methods, so each tool steers vendor extensions
and spec overrides to the correct entry point. A replaced spec method keeps its registry envelope
class, which is why `replace*` takes no class argument.
