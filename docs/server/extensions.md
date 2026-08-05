# Extensions

Extensions (SEP-2133) bundle a capability identifier, its settings, and the methods it owns into
one object the builder consumes. They are disabled by default: nothing is advertised or served
until you enable the extension explicitly.

```php
use Nexus\Mcp\Server\ServerBuilder;

$server = new ServerBuilder()
    ->setServerInfo('demo', '1.0.0')
    ->enableExtension(new AcmeSnapshotExtension($store))
    ->build();
```

## Declaring an extension

A server extension implements `ServerExtensionInterface`, a closed declarative surface consumed at
enable-time:

| Method | Declares |
| --- | --- |
| `getIdentifier()` | The `{vendor-prefix}/{name}` capability identifier, e.g. `io.modelcontextprotocol/tasks`. |
| `getSettings()` | The settings object advertised under `capabilities.extensions[identifier]`. Empty means supported with no settings. |
| `getRequests()` | Inbound request method to `JsonRpcRequest` subclass, so the parser recognises the method. |
| `getNotifications()` | Inbound notification method to `JsonRpcNotification` subclass. |
| `getRequestHandlers()` | A handler per `getRequests()` key. |
| `getNotificationHandlers()` | A handler per `getNotifications()` key. |

`enableExtension()` validates the whole declaration before accepting it: the identifier must follow
the `_meta` key grammar with a mandatory prefix, the settings must be a string-keyed object, class
and handler maps must pair the same method keys, every class must declare the method it is keyed
under, request classes must implement the `ClientRequest` marker with `RequestParams`-typed params
(the dispatcher rejects anything else), and a method may not collide with the MCP specification,
another enabled extension, or a builder-registered handler. Collisions are symmetric:
`addRequestHandler()` equally refuses a method an enabled extension owns. The declaration is
snapshotted as validated, so later getter calls cannot change what the built server serves.

## Negotiation and gating

The capability entry rides the `server/discover` response, and the client declares its supported
extensions on every request via the `_meta` `io.modelcontextprotocol/clientCapabilities` envelope.
Every extension request handler is wrapped in a gate that enforces the client half: a request for
an extension-owned method from a client whose per-request capabilities did not declare the
extension is answered `-32021` (`MissingRequiredClientCapability`) naming the identifier both in
the message (`extensions.{identifier}`) and in `error.data.requiredCapabilities`, and the handler
never runs. An extension-owned method has no
core behaviour to fall back to, so rejection is the only conformant answer.

Extension **notifications** are not gated: a notification's `_meta` carries no capability
declaration to check, so notification-level enforcement, where an extension needs it, belongs to
the handler itself.

Handlers read the per-request declaration themselves when they need the settings:

```php
$declared = $context->meta->clientCapabilities->extensions['com.example/snapshot'] ?? null;
```

## Decorating built-in handlers

An extension that changes how a specification method behaves, rather than adding methods of its
own, additionally implements `RequestHandlerDecoratorInterface`. Its
`getRequestHandlerDecorators()` map pairs a spec-registry request method with a closure that
receives the handler finally serving that method, whether the built-in default or a
`replaceRequestHandler()` replacement, and returns the wrapping handler. Decorators are applied
at `build()`, a decorated method must have a handler to wrap or the build fails, and when several
enabled extensions decorate the same method they compose with the last-enabled extension
outermost.

A decorator's output is served ungated: unlike an extension-owned method, a spec method must keep
serving clients that never declared the extension, so any per-request refusal is the decorator's
own decision. The shipped [tasks extension](tasks.md) is the worked example: it decorates
`tools/call` with a broker that only diverts a call into a task when the client declared the
capability.

## Official extensions

Two official extensions ship with the SDK. The [tasks extension](tasks.md) exercises the whole
surface above: owned methods, handlers, and a `tools/call` decorator. The
[apps extension](apps.md) sits at the other end: it defines no methods, so
`AppsServerExtension` only advertises the capability slot and the substance is typed `_meta.ui`
metadata on the tools and resources you already register.
