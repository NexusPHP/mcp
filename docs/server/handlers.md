# Custom request and notification handlers

For vendor-extension methods (those outside the MCP spec):

```php
->addRequestHandler('acme/lookup', new MyLookupHandler())
->addNotificationHandler('acme/heartbeat', new MyHeartbeatHandler())
```

Both reject spec-reserved methods. To override the SDK's built-in handler for a spec method
(e.g. to take over `tools/list`), use the `replace*` variants:

```php
->replaceRequestHandler('tools/list', new MyListToolsHandler())
->replaceNotificationHandler('notifications/cancelled', new MyCancelledHandler())
```

The `replace*` variants in turn reject non-spec methods, so each tool steers vendor extensions
and spec overrides to the correct entry point.
