# Notification handlers

Register handlers for server-to-client notifications at build time. A handler implements
`NotificationHandlerInterface`. The dispatch table is keyed by method name.

```php
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;

->addNotificationHandler(ToolListChangedNotification::getMethod(), $myHandler)
```

A build-time `notifications/progress` handler receives every progress notification whose token is **not**
claimed by an in-flight `callTool(onProgress:)`. The two coexist: per-call `onProgress` takes its own token,
and the build-time handler sees the rest.
