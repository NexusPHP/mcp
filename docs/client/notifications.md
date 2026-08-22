# Notification handlers

Register handlers for server-to-client notifications at build time. A handler implements
`NotificationHandlerInterface`. The dispatch table is keyed by method name.

```php
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;

->addNotificationHandler(ToolListChangedNotification::class, $myHandler)
```

The class names the method through its `getMethod()`. A spec method keeps its registry envelope class, and
registration refuses a lookalike declaring the same method. A vendor notification registers the
`JsonRpcNotification` subclass that parses it the same way:

```php
->addNotificationHandler(AcmeHeartbeatNotification::class, $myHandler)
```

## Progress notifications

A build-time `notifications/progress` handler receives every progress notification whose token is **not** claimed
by an in-flight `callTool(onProgress:)`. The two coexist. A per-call `onProgress` takes its own token, and the
build-time handler sees the rest.
