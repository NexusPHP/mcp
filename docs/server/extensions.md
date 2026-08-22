# Extensions

Extensions (SEP-2133) bundle a capability identifier, its settings, and the methods it owns into one object the
builder consumes. They are disabled by default. Nothing is advertised or served until you enable the extension
explicitly.

```php
use Nexus\Mcp\Server\ServerBuilder;

$server = (new ServerBuilder())
    ->setServerInfo('demo', '1.0.0')
    ->enableExtension(new AcmeSnapshotExtension($store))
    ->build();
```

## Declaring an extension

A server extension implements `ServerExtensionInterface`, a closed declarative surface the builder consumes at
enable time:

| Method | Declares |
| --- | --- |
| `getIdentifier()` | The `{vendor-prefix}/{name}` capability identifier, e.g. `io.modelcontextprotocol/tasks`. |
| `getSettings()` | The settings object advertised under `capabilities.extensions[identifier]`. Empty means supported with no settings. |
| `getRequests()` | The `JsonRpcRequest` subclasses it serves, each naming its method through `getMethod()`. |
| `getNotifications()` | The `JsonRpcNotification` subclasses it serves. |
| `getRequestHandlers()` | A handler per `getRequests()` class, keyed by that class's method. |
| `getNotificationHandlers()` | A handler per `getNotifications()` class, keyed by that class's method. |

### Validation at enable time

`enableExtension()` validates the whole declaration before it accepts it:

- The identifier must follow the `_meta` key grammar, with a mandatory prefix.
- The settings must be a string-keyed object.
- The handler maps must carry exactly the methods the declared classes name, and no class may name a method
  twice.
- Request classes must implement the `ClientRequest` marker with `RequestParams`-typed params. The dispatcher
  rejects anything else.
- A method may not collide with the MCP specification, another enabled extension, or a builder-registered
  handler. Collisions are symmetric: `addRequestHandler()` equally refuses a method an enabled extension owns.

The builder snapshots the declaration as validated, so later getter calls cannot change what the built server
serves.

## Negotiation and gating

The capability entry rides the `server/discover` response. The client declares its supported extensions on every
request through the `_meta` `io.modelcontextprotocol/clientCapabilities` envelope.

A gate wraps every extension request handler and enforces the client half. When a client whose per-request
capabilities did not declare the extension requests an extension-owned method, the gate answers `-32021`
(`MissingRequiredClientCapability`). It names the identifier both in the message (`extensions.{identifier}`) and
in `error.data.requiredCapabilities`, and the handler never runs. An extension-owned method has no core behaviour
to fall back to, so rejection is the only conformant answer.

Extension **notifications** are not gated. A notification's `_meta` carries no capability declaration to check.
Where an extension needs notification-level enforcement, that belongs to the handler itself.

Handlers read the per-request declaration themselves when they need the settings:

```php
$declared = $context->meta->clientCapabilities->extensions['com.example/snapshot'] ?? null;
```

## Decorating built-in handlers

An extension that changes how a specification method behaves, rather than adding methods of its own, also
implements `RequestHandlerDecoratorInterface`. Its `getRequestHandlerDecorators()` map pairs a spec-registry
request method with a closure. The closure receives the handler that finally serves that method, whether the
built-in default or a `replaceRequestHandler()` replacement, and returns the wrapping handler.

Decorators are applied at `build()`. A decorated method must have a handler to wrap, or the build fails. When
several enabled extensions decorate the same method, they compose with the last-enabled extension outermost.

A decorator's output is served ungated. Unlike an extension-owned method, a spec method must keep serving clients
that never declared the extension, so any per-request refusal is the decorator's own decision. The shipped
[tasks extension](tasks.md) is the worked example. It decorates `tools/call` with a broker that only diverts a
call into a task when the client declared the capability.

## Official extensions

Four official extensions ship with the SDK. The [tasks extension](tasks.md) exercises the whole surface above:
owned methods, handlers, and a `tools/call` decorator. The [apps extension](apps.md) sits at the other end. It
defines no methods, so `AppsServerExtension` only advertises the capability slot, and the substance is typed
`_meta.ui` metadata on the tools and resources you already register.

The two [OAuth extensions](../auth/extension-grants.md) are advertisement-only on the server too. Those are client
credentials (SEP-1046) and enterprise-managed authorization (SEP-990). Their grants run at the HTTP layer inside
the client's `AuthorizedHttpClient`, so `ClientCredentialsServerExtension` and
`EnterpriseAuthorizationServerExtension` declare no methods and no settings. Enabling one advertises under
`capabilities.extensions` which authorization model the deployment runs. The [resource server](../auth/server.md)
validates the resulting tokens like any other.

```php
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsServerExtension;
use Nexus\Mcp\Extension\Auth\Enterprise\EnterpriseAuthorizationServerExtension;

$server = (new ServerBuilder())
    ->setServerInfo('acme', '1.0.0')
    ->enableExtension(new ClientCredentialsServerExtension())
    ->enableExtension(new EnterpriseAuthorizationServerExtension())
    ->build();
```
