# Custom request and notification handlers

For vendor-extension methods, those outside the MCP spec, registration names the handler and the envelope class
that parses the method. The class is what makes the method parseable at all. The message parser only recognises
methods it has a class for, and answers `-32601` for the rest.

```php
->addRequestHandler('acme/lookup', new MyLookupHandler(), AcmeLookupRequest::class)
->addNotificationHandler('acme/heartbeat', new MyHeartbeatHandler(), AcmeHeartbeatNotification::class)
```

## The envelope class

`AcmeLookupRequest` extends `JsonRpcRequest`, returns `'acme/lookup'` from `getMethod()`, and implements the
`ClientRequest` marker. Registration rejects a class that declares a different method or lacks the marker, since
the dispatcher only serves `ClientRequest` requests.

Its params must be `RequestParams`-typed. They carry the lifecycle `_meta` every request is gated on. Otherwise
the dispatcher answers `-32600`. Notification classes extend `JsonRpcNotification` the same way, without the
marker.

## Overriding a spec method

Both methods reject spec-reserved methods. To override the SDK's built-in handler for a spec method, for example
to take over `tools/list`, use the `replace*` variants:

```php
->replaceRequestHandler('tools/list', new MyListToolsHandler())
->replaceNotificationHandler('notifications/cancelled', new MyCancelledHandler())
```

The `replace*` variants in turn reject non-spec methods, so each entry point steers vendor extensions and spec
overrides to the correct place. A replaced spec method keeps its registry envelope class, which is why `replace*`
takes no class argument.
