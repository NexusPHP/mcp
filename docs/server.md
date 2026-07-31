# Server API

The `Server` class runs against a `TransportInterface` and blocks the caller until the transport closes.
Build one with the fluent `ServerBuilder` and run it against any transport implementation.

```php
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

$server = new ServerBuilder()
    ->setServerInfo(name: 'my-server', version: '1.0.0')
    // ... register features ...
    ->build()
;

$server->run(new StdioServerTransport());
```

`Server::run()` returns when the transport closes (stdin EOF for the stdio transport). A request-scoped
transport such as [Streamable HTTP](transports.md#streamablehttpservertransport) uses `Server::listen()`
instead, which attaches the dispatcher and returns so the HTTP host keeps driving the loop.

## Server info

Required before `build()`.

```php
->setServerInfo(
    name: 'my-server',
    version: '1.0.0',
    title: 'My Friendly Server',
    description: 'A short description the server advertises via server/discover.',
    websiteUrl: 'https://example.com',
)
```

The server stamps this identity onto the `_meta` of every result it sends, under the
`io.modelcontextprotocol/serverInfo` key, which is where the spec asks servers to identify themselves. It is
self-reported and unverified, so clients are told to treat it as display and logging material rather than as a
behavioural signal.

`setServerInfoDisclosure()` controls how much of it travels, via `Nexus\Mcp\Server\ServerInfoDisclosure`:

| Case | `server/discover` | Every other result |
| --- | --- | --- |
| `Full` (default) | The whole block | The whole block |
| `NameAndVersion` | The whole block | Only `name` and `version` |
| `None` | Nothing | Nothing |

`NameAndVersion` suits a server with icons and descriptions: the client collects those once at discovery
rather than on every response. `build()` requires `setServerInfo()` under all three, `None` simply never
sends what it validated.

```php
->setServerInfo(name: 'my-server', version: '1.0.0')
->setServerInfoDisclosure(ServerInfoDisclosure::NameAndVersion)
```

A handler that sets `serverInfo` on the result's `_meta` itself keeps what it set: the stamp fills an empty
slot rather than overwriting one. That is what lets a proxy forward the identity of the server it fronts, and
it is also how `server/discover` keeps the full block while other results are trimmed.

## Instructions

Optional. Advertised to the client via `server/discover`. Use it to give models guidance about how to use
the server.

```php
->setInstructions('Use the search_docs tool before answering questions about Nexus.')
```

## Logger

Optional. Defaults to `Psr\Log\NullLogger`. Logs go to whatever PSR-3 logger you provide. MCP servers MUST
NOT write to STDOUT outside of the JSON-RPC stream. Target STDERR or a file.

```php
->setLogger($psrLogger)
```

## In-flight dispatch cap

Optional and off by default. Without it, a peer that sends faster than handlers finish accumulates one
coroutine per message until the process runs out of memory. `setMaxInFlightDispatches()` bounds that.

```php
->setMaxInFlightDispatches(64)
```

Past the cap, a request is answered `-32000` (`SdkErrorCode::Overloaded`) and a notification is dropped
without a reply, because JSON-RPC 2.0 §4.1 forbids answering one. Shedding happens before the request id is
claimed, so the server holds no state for a shed request and a retry is never rejected as a duplicate.

Pick a number from what your handlers cost, not from request rate: the cap counts handlers running
concurrently, and it releases as each one finishes. The budget is shared, so a registered notification
handler occupies a slot for as long as it runs. A notification whose method has no handler costs nothing.

Over Streamable HTTP a shed request carries `503 Service Unavailable` under the default `ResponseMode::Auto`
and under `ResponseMode::Json`. Under `ResponseMode::Sse` it carries `200` with the error in a stream frame,
as every dispatcher-produced error does there: an SSE response commits its status when the stream opens,
before any frame exists. Front a proxy that keys on `503` with `Auto` or `Json`.

This composes with `RequestBodySizeLimitMiddleware`, which caps a single body rather than concurrency.

## Tools

```php
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ServerContext;

->addTool(
    tool: new Tool(
        name: 'search_docs',
        inputSchema: [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ],
        description: 'Searches the docs index.',
    ),
    executor: static function (?array $args, ServerContext $context): CallToolResult {
        $query = is_string($args['query'] ?? null) ? $args['query'] : '';

        return new CallToolResult(content: [new TextContent(text: "Results for {$query}")]);
    },
)
```

The executor can be either a `\Closure` or a class implementing `ToolExecutorInterface`. Registering at
least one tool advertises the `tools` capability automatically.

Runtime exceptions thrown out of a tool executor are converted into a `CallToolResult` with `isError: true`
and a single `TextContent` carrying `"Tool execution failed."`. The underlying throwable is logged at `error`
level on the PSR-3 logger configured via `ServerBuilder::setLogger()`. To surface error detail to the LLM,
return `new CallToolResult(content: [...], isError: true)` from the executor instead of throwing.
Protocol-level conditions (`ToolNotFoundException`, etc.) still surface as JSON-RPC errors.

> [!WARNING]
> The generic-text wrap above only covers the `\Throwable` arm. Messages thrown via
> `AbstractJsonRpcProtocolException` subclasses (`InvalidParamsException` and similar) are surfaced
> **verbatim** in the JSON-RPC `error.message` field. Keep those strings free of paths, credentials,
> connection strings, and any other sensitive data. The recommended pattern for surfacing tool errors is
> `return new CallToolResult(content: [...], isError: true)`, not throwing a protocol exception.

### Structured content

A tool may return a `structuredContent` object instead of, or alongside, its `content` blocks:

```php
return new CallToolResult(
    content: [],
    structuredContent: ['temperature' => 22.5, 'unit' => 'celsius'],
);
```

For backwards compatibility, the spec recommends that a tool returning `structuredContent` also return
the serialised JSON in a `TextContent` block. When the executor leaves `content` empty, the handler adds
that block for you. Provide your own `content` to keep control of the text representation. A non-empty
`content` list is passed through untouched.

### Schema validation

A tool call is validated against the tool's schemas on the way in and on the way out:

- The call `arguments` are validated against the tool's `inputSchema`. A non-conforming payload fails the
  call with a JSON-RPC `InvalidParams` error before the executor runs.
- When a (non-error) result carries `structuredContent` and the tool declares an `outputSchema`, the
  result is validated against that schema. A non-conforming result is logged server-side and surfaced to
  the client as a generic error result, so malformed structured data is never sent.

Validation is backed by [opis/json-schema](https://github.com/opis/json-schema) (JSON Schema draft
2020-12) by default. Supply your own engine by implementing `SchemaValidatorInterface` and registering it
with `ServerBuilder::setSchemaValidator()`.

```php
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;

$server = new ServerBuilder()
    ->setSchemaValidator($myValidator) // any SchemaValidatorInterface
    // ...
    ->build()
;
```

## Prompts

```php
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;

->addPrompt(
    prompt: new Prompt(name: 'summarise', description: 'Summarises the user input.'),
    renderer: static fn(?array $args, ServerContext $context): GetPromptResult => new GetPromptResult(messages: [
        new PromptMessage(
            role: Role::User,
            content: new TextContent(text: 'Summarise the following ...'),
        ),
    ]),
)
```

The renderer can be a `\Closure` or a `PromptRendererInterface`.

## Resources

Static URIs:

```php
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

->addResource(
    resource: new Resource(name: 'config', uri: 'config://app.toml', mimeType: 'application/toml'),
    reader: static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: file_get_contents('/etc/app.toml'))],
        ttlMs: 60_000,
        cacheScope: CacheScope::Public,
    ),
)
```

URI templates ([RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570)):

```php
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;

->addResourceTemplate(
    template: new ResourceTemplate(name: 'user', uriTemplate: 'users://{userId}'),
    reader: static fn(string $uri, array $vars, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: loadUser($vars['userId']))],
        ttlMs: 0,
        cacheScope: CacheScope::Private,
    ),
)
```

`$vars` carries the resolved template variables (`['userId' => '123']` for `users://123`). The reader can
be a `\Closure` or a `ResourceReaderInterface` / `TemplatedResourceReaderInterface`.

### Cache hints

`ReadResourceResult` and the `*/list` results require two cache hints the server returns to the client:
`ttlMs` (how many milliseconds the client MAY treat the response as fresh, `0` meaning re-fetch every time)
and `cacheScope` (`CacheScope::Public` for a response any shared cache MAY serve to any user, or
`CacheScope::Private` for one only the requesting user's client MAY cache). Both default to `ttlMs: 0` and
`CacheScope::Private`. `setTtlMs()` and `setCacheScope()` change them for every store the builder assembles
from its `add*()` entries:

```php
->setTtlMs(60_000)
->setCacheScope(CacheScope::Public)
```

To vary them per feature, build that store yourself and pass it through the matching setter. A store
supplied that way keeps its own values and ignores the builder-level defaults:

```php
->setToolStore(new ToolStore($entries, ttlMs: 60_000, cacheScope: CacheScope::Public))
```

`$entries` is what `addTool()` would have built: a map of tool name to `ToolEntry`, each pairing a `Tool`
with its executor. `PromptEntry`, `ResourceEntry`, and `ResourceTemplateEntry` do the same for the other
stores. Building a store yourself is also how you hand the same instance to something else that needs it,
such as the `Mcp-Param-{Name}` validation on
[`SecuredHttpEndpoint`](transports.md#securing-the-endpoint).

```php
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;

$entries = ['greet' => new ToolEntry($greetTool, new ClosureToolExecutor($greetExecutor))];
```

## Pagination

The `*/list` results are paginated by `CursorPaginator`, which each built-in store composes. A store
returns at most 50 entries per page and, when more remain, a `nextCursor` the client passes back to fetch
the next page. `setPageSize()` changes that for every
store the builder assembles from its `add*()` entries:

```php
->setPageSize(200)
```

The size must be a positive integer. As with the cache hints, a store supplied through `setToolStore()` and
its siblings keeps its own page size.

## Custom stores

Completions are served entirely by a store you provide:

```php
->setCompletionStore(new MyCompletionStore())
```

The store implements `CompletionStoreInterface` and is consulted on `completion/complete` requests.
Registering it advertises the `completions` capability.

The in-memory stores that back tools, prompts, resources, and resource templates can likewise be swapped for
a custom implementation. A setter replaces the store the builder would otherwise assemble from the matching
`add*()` entries, so registering a store also lights up that feature's capability.

```php
->setToolStore(new MyToolStore())                         // ToolStoreInterface
->setPromptStore(new MyPromptStore())                     // PromptStoreInterface
->setResourceStore(new MyResourceStore())                 // ResourceStoreInterface
->setResourceTemplateStore(new MyResourceTemplateStore()) // ResourceTemplateStoreInterface
```

Each store implements the read surface its built-in handlers depend on (`list()` plus `call()` / `get()` /
`read()`). The built-in in-memory stores go further and implement the matching `Mutable*StoreInterface`,
which adds `add*()` / `remove*()` plus the `onListChanged()` seam from `ListChangeSourceInterface`. A custom
store may implement `ListChangeSourceInterface` alone when it can observe changes it does not itself make
(a database-backed listing, say), and stays a plain read surface when it cannot. `CompositeResourceStore`
forwards `onListChanged()` to whichever of its two inner stores reports changes. When a custom store and the matching `add*()` entries are both supplied, the custom store wins
and those entries are ignored. A custom resource store still composes with a resource template store (custom
or entry-built) for `resources/read`.

## Custom request and notification handlers

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

## Attribute discovery

Tools, prompts, resources, and the server identity can also be declared with attributes (`#[AsTool]`,
`#[AsPrompt]`, `#[AsResource]`, `#[AsResourceTemplate]`, `#[AsServer]`) on a plain object and registered in
one call with `ServerBuilder::register()`. See [Attribute discovery](attribute-discovery.md) for the full
reference.

## Capability advertisement

`ServerCapabilities` is derived automatically from what you registered.

| Capability slot | Lit up by |
| --- | --- |
| `tools` | At least one `addTool(...)`, `setToolStore(...)`, or both `tools/list` and `tools/call` `replaceRequestHandler(...)`. |
| `prompts` | At least one `addPrompt(...)`, `setPromptStore(...)`, or both `prompts/list` and `prompts/get` `replaceRequestHandler(...)`. |
| `resources` | At least one `addResource(...)` / `addResourceTemplate(...)`, `setResourceStore(...)` / `setResourceTemplateStore(...)`, or both `resources/list` and `resources/read` `replaceRequestHandler(...)`. |
| `completions` | `setCompletionStore(...)`, or `completion/complete` `replaceRequestHandler(...)`. |

`listChanged` and `resources.subscribe` are advertised only when the subscription store says it will honour
that notification type **and** the feature's own store can report its changes (it implements
`ListChangeSourceInterface`, as the built-in in-memory stores do). The store is the single declarer: the
builder asks it what it honours rather than deciding independently, so a capability can never promise more
than an acknowledgement will grant. Advertising either without both is a promise the server cannot keep, and
the conformance suite scores an undelivered `list_changed` as a failure.

## Subscriptions

`subscriptions/listen` opens a long-lived stream the server pushes notifications down. Register a store to
serve it:

```php
$subscriptions = new SubscriptionStore(
    toolsListChanged: true,
    promptsListChanged: true,
    resourcesListChanged: true,
    resourceSubscriptions: true,
    maxSubscriptions: 1024,
);

$builder->setSubscriptionStore($subscriptions);
```

`maxSubscriptions` bounds how many streams one store holds open, defaulting to
`SubscriptionStore::DEFAULT_MAX_SUBSCRIPTIONS` (1024). A listen request past the limit is refused with
`-32603` before any acknowledgement, so the client never sees a stream it does not have.

The constructor flags say what this server can actually deliver, and they are what `build()` reads to derive
the `listChanged` and `subscribe` capabilities. A listen request is acknowledged with the intersection of
what the client asked for and what those flags allow, and the acknowledgement is always the first message on
the stream. Types the server cannot deliver are omitted from it rather than denied. A store left at its
defaults honours nothing, so nothing is advertised.

Every message on a stream carries `io.modelcontextprotocol/subscriptionId` in `_meta`, naming the id the
client sent on its `subscriptions/listen` request.

Mutating a built-in store announces itself, because `build()` routes each store's `onListChanged()` to the
matching emit. The resource template store routes to `notifications/resources/list_changed` alongside the
resource store, since a template expansion changes what the server can read:

```php
$builder->getToolStore()->addTool($tool, $executor);  // reaches every stream that asked for toolsListChanged
```

`build()` may be called once per builder, and every registration method is closed once it has run. A second
`build()`, or any `add*()` / `set*()` / `register()` after one, throws `BuilderAlreadyBuiltException` rather
than being silently dropped: the built server already holds the stores and the list-change listeners.

Resource *contents* changing is not something a store can observe, so publish it yourself:

```php
$subscriptions->emitResourceUpdated('file:///etc/cfg');
```

Announcements coalesce per event-loop tick, so a burst of mutations reaches each stream once. A
`list_changed` carries no payload, so nothing is lost: the client re-lists either way.

A stream ends when the client cancels it (`notifications/cancelled` over stdio, or closing the SSE body over
HTTP), or when the server tears it down with `close($entry)` or `closeAll()`. A server-initiated teardown
sends the `notifications/cancelled` the spec requires, naming the `subscriptions/listen` request, and then
answers the held-open request with the empty result the spec calls graceful closure.

`close()` takes the `SubscriptionEntry` that `open()` returned, not a request id. Request ids are unique per
connection, and a sessionless endpoint serves many connections through one store, so two clients that both
number their listen request `1` would otherwise share a slot. Use `discard($entry)` to deregister a stream
whose client already walked away, which announces nothing.

`Server` calls `closeAll()` on drain, before awaiting in-flight coroutines, so a held-open stream cannot
block shutdown. A stream opened after that point is settled immediately rather than held, since `Amp\async()`
only queues a handler and a listen request can first run after the drain began.

Over Streamable HTTP the SDK ignores an inbound `notifications/cancelled` from a client. The spec makes
closing the response stream the cancellation signal there, and a client's request id names its own id space
rather than the transport-internal one the server dispatches under.

A `subscriptions/listen` always answers over SSE, whatever
[`ResponseMode`](transports.md#streamablehttpservertransport) the transport is configured with. The buffered
path would hold the POST open with nowhere to push the acknowledgement.

A held-open listen coroutine does not count against
[`setMaxInFlightDispatches()`](#in-flight-dispatch-cap), because it opens a subscription rather than being
processed. `maxSubscriptions` is what bounds streams. The exemption is that narrow: a tool handler awaiting
slow I/O still holds a slot, since shedding a pile-up of those is what the cap is for.

## What `ServerContext` exposes to a handler

Every handler closure receives a `ServerContext` as its last argument.

| Property / method | Purpose |
| --- | --- |
| `$context->requestId` | The originating `RequestId`. |
| `$context->cancellation` | An `Amp\Cancellation` token. Pass it to any `await()` so client `notifications/cancelled` can interrupt long-running work. |
| `$context->meta` | The request's `_meta` object: the client's `protocolVersion` and `clientCapabilities`, the optional `clientInfo`, plus `progressToken`. Read client capabilities per request, never inferred from a prior one. |
| `$context->reportProgress($progress, $total, $message)` | Emits a `notifications/progress` if the original request carried a `progressToken`. |
| `$context->receiveContext` | What the transport knew about the delivery. Over Streamable HTTP that is `request` (the PSR-7 `ServerRequestInterface`) and `authInfo` (the `VerifiedAccessToken` an authentication middleware verified, see [authorization](authorization.md#reading-the-token-in-a-handler)). Both are `null` over stdio. |
| `$context->inputResponses` | The client's answers to a prior `InputRequiredResult`, keyed by the identifiers that result assigned, or `null` on a first call. |
| `$context->requestState` | The opaque continuation token that result carried, echoed back unchanged, or `null` on a first call. |

## Asking the client for input

A `tools/call`, `prompts/get` or `resources/read` handler that cannot finish without something from the
client returns an `InputRequiredResult` instead of its normal result. The client fulfils the requests it
names and calls the same method again, carrying its answers.

```php
->addTool(
    tool: new Tool(name: 'deploy', inputSchema: ['type' => 'object']),
    executor: static function (?array $args, ServerContext $context): CallToolResult|InputRequiredResult {
        $answer = $context->inputResponses['confirm'] ?? null;

        if (! $answer instanceof ElicitResult || ElicitAction::Accept !== $answer->action) {
            return new InputRequiredResult(
                inputRequests: ['confirm' => new ElicitRequest(params: new ElicitRequestFormParams(
                    message: 'Deploy to production?',
                    requestedSchema: new ElicitRequestedSchema(
                        properties: ['ok' => new BooleanSchema()],
                        required: ['ok'],
                    ),
                ))],
                requestState: $signer->sign('awaiting-confirmation'),
            );
        }

        return new CallToolResult(content: [new TextContent(text: 'Deployed.')]);
    },
)
```

Three things are worth knowing before you write one.

**The client resends only the newest round's answers.** Anything an earlier round learned has to travel in
`requestState`, which is why a multi-round handler reads its position from the state rather than from an
accumulated `inputResponses` map.

**`requestState` is opaque to the client and unverified on arrival.** The spec has the client echo it back
untouched, so a hostile one may echo back something else entirely. `RequestStateSigner` mints and checks it:

```php
$states = new RequestStateSigner($secretFromConfig);

// Minting, when the handler asks for input.
$state = $states->sign('awaiting-confirmation');

// Checking, when the call comes back.
$payload = $states->verify($context->requestState)
    ?? throw new InvalidParamsException($context->requestId, 'The "requestState" failed its integrity check.');
```

`verify()` returns the payload it signed, or `null` for a state this server did not mint. Distinguish that
from a first call, where `$context->requestState` is `null` and the handler should ask rather than reject.
`RequestStateSigner::generate()` draws a key for a server whose states never outlive its own process. Anything
longer-lived wants a key from configuration, so a state survives a restart or a second instance behind a load
balancer.

The payload is signed, not encrypted, and travels in the clear: put a continuation marker in it, never a
secret. Expiry is not built in either. Encode a timestamp in the payload and check it after `verify()` if a
state should go stale.

**Only elicitation is available to ask for.** The spec's `InputRequest` union also admits
`sampling/createMessage` and `roots/list`, but both are deprecated as of 2026-07-28 (SEP-2577) and this SDK
does not model them, so `ElicitRequest` is the only thing a handler can put in `inputRequests`.

[`conformance/MultiRoundServer.php`](../conformance/MultiRoundServer.php) is the worked example, covering a
single round, a signed continuation token, a two-question sequence, and the same flow on a prompt.

## Lifecycle

1. **`build()`** validates the configuration (e.g. server info must be set) and returns a `Server` instance.
2. **`run($transport)`** registers the listener chain on the transport, starts it, and blocks until the
   transport closes.
3. While running, the dispatcher classifies each inbound envelope and routes it straight to its handler.
   The protocol is stateless, so every request dispatches immediately, with the client's identity and
   capabilities read from the request's `_meta`. Requests for an unregistered method get a `MethodNotFound`
   error, and malformed envelopes get an `InvalidRequest` or `ParseError` error.
4. **Shutdown** is signalled by the transport closing (e.g. stdin EOF). The dispatcher drains in-flight
   coroutines before the transport's close listeners fire, so responses already in flight are flushed
   before the process exits.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Attribute discovery](attribute-discovery.md)**: declare features with `#[AsTool]` and friends.
- **[Client API](client.md)**: the symmetric client-side reference.
- **[Transports](transports.md)**: the `StdioServerTransport` and `StreamableHttpServerTransport` contracts,
  plus the PSR-15 middleware stack that secures the HTTP endpoint.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
