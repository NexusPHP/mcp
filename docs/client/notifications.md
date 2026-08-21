# Notification handlers

Register handlers for server-to-client notifications at build time. A handler implements
`NotificationHandlerInterface`. The dispatch table is keyed by method name.

```php
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;

->addNotificationHandler(ToolListChangedNotification::getMethod(), $myHandler)
```

Spec-defined notifications already parse, so the two arguments above are enough. A spec method keeps its
registry envelope class, and registration refuses any other. A vendor notification method must also name the
`JsonRpcNotification` subclass that parses it, and registration rejects a class that declares a different method:

```php
->addNotificationHandler('acme/heartbeat', $myHandler, AcmeHeartbeatNotification::class)
```

## Progress notifications

A build-time `notifications/progress` handler receives every progress notification whose token is **not** claimed
by an in-flight `callTool(onProgress:)`. The two coexist. A per-call `onProgress` takes its own token, and the
build-time handler sees the rest.
