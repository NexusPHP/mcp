# Attribute discovery

Attribute discovery is a higher-level alternative to the manual `addTool()` / `addPrompt()` /
`addResource()` / `addResourceTemplate()` / `addPromptCompletion()` / `addResourceTemplateCompletion()` /
`setServerInfo()` calls described in the [Server API](server.md). Mark methods on a plain object with attributes, then hand the object to
`ServerBuilder::register()`. The explicit builder methods remain the substrate. This is sugar over them, so
the two compose freely.

```php
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

#[AsServer(name: 'my-server', version: '1.0.0', instructions: 'Use the tools wisely.')]
final class MyServer
{
    /**
     * @param string $city The city to look up.
     */
    #[AsTool(description: 'Returns the weather for a city.')]
    public function weather(string $city, ServerContext $context): string
    {
        return "It is sunny in {$city}.";
    }

    #[AsResource(uri: 'config://app', mimeType: 'application/json')]
    public function appConfig(string $uri): string
    {
        return file_get_contents('/etc/app.json');
    }
}

$server = new ServerBuilder()->register(new MyServer())->build();
$server->run(new StdioServerTransport());
```

`register()` takes any number of source objects and returns the builder, so it chains with the manual
`add*` / `set*` methods. Each source must carry at least one discoverable attribute. A source with no
`#[AsServer]` and no attribute-marked method throws `MissingDiscoveryAttributeException`, which catches
typo'd attribute names and objects passed in by mistake.

## What the attributes map to

| Attribute | Target | Becomes |
| --- | --- | --- |
| `#[AsTool]` | method | a tool, with `inputSchema` inferred from the signature |
| `#[AsPrompt]` | method | a prompt, with `arguments` inferred from the signature |
| `#[AsResource]` | method | a static resource (requires `uri`) |
| `#[AsResourceTemplate]` | method | an RFC 6570 templated resource (requires `uriTemplate`) |
| `#[AsCompletion]` | method | a [completion provider](server/completions.md#attribute-sugar) for one prompt argument or template variable (requires `argument` plus `prompt` or `uriTemplate`, repeatable) |
| `#[AsServer]` | class | the server identity and instructions |

Each method attribute carries the same optional metadata as its schema class (`title`, `description`,
`icons`, `annotations`, `meta`, ...). When `name` is omitted it falls back to the method name. The
`meta` slot is also how extension metadata attaches, such as an [MCP Apps](server/apps.md)
`_meta.ui` tool link.

## Input schemas and arguments

For a tool, the `inputSchema` is generated from the parameter types and `@param` docblock lines: native and
docblock types map to JSON Schema, `@param` text becomes the property description, and constraints such as
`non-empty-string` or `int<1, 5>` are carried through. Override or extend the result per parameter (or for
the whole method) with `#[InputSchema(...)]`.

For a prompt, each parameter becomes a prompt argument: its `@param` line supplies the description, and a
parameter without a default value is marked required. Prompt arguments and resource URI variables are bound
from strings, so a prompt, resource, or resource-template parameter must accept one: `string`, a string-backed
or pure enum, or an untyped parameter. Other types (non-string scalars, int-backed enums, classes,
intersection types) throw `UnsupportedParameterTypeException` at registration.

A `ServerContext` parameter is injected and left out of the schema. All other arguments are bound to
parameters by name, and backed or pure enum parameters are hydrated from the argument value.

A completion method's parameters are bound by type instead: a `ServerContext` parameter receives the
context, an `array` parameter the client's resolved context arguments, and any other parameter the partial
value being typed. That last kind must take a raw string (`string`, `mixed`, a union containing one, or
untyped), with no enum hydration on this path, so an enum parameter throws
`UnsupportedParameterTypeException` at registration even though a prompt method could declare it.

A variadic tool parameter (`T ...$x`) maps to an array input (`{"type": "array", "items": <T>}`), is never
required, and the supplied list is spread back into the call. Variadic parameters are accepted only on tools,
since prompts and resources receive flat string values. A variadic on a prompt, resource, or resource
template throws `UnsupportedVariadicParameterException`.

A tool parameter typed as an instantiable class is expanded into an object schema built from that class's
constructor parameters, and the handler receives a constructed instance. Expansion goes one level: a
constructor parameter that is itself a class (a nested object), along with interfaces, abstract classes, and
built-in classes such as `\DateTimeImmutable`, is not expanded and throws `SchemaGenerationException` at
registration.

```php
final readonly class Coordinate
{
    public function __construct(public float $latitude, public float $longitude) {}
}

#[AsTool(description: 'Stores a pin.')]
public function pin(Coordinate $at): string
{
    return "{$at->latitude},{$at->longitude}";
}
```

```php
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Attribute\InputSchema;

/**
 * @param string $unit The temperature unit.
 */
#[AsTool(description: 'Forecasts the weather.')]
public function forecast(
    #[InputSchema(enum: ['celsius', 'fahrenheit'])]
    string $unit,
    int $days,
): string {
    // ...
}
```

## Return values

A handler may return the full result object (`CallToolResult`, `GetPromptResult`, `ReadResourceResult`,
`CompleteResult`) or a shorthand the SDK adapts:

| Handler | Shorthand returns |
| --- | --- |
| tool | a `string` (wrapped as `TextContent`), a content block, a list of content blocks, or an array (treated as `structuredContent`) |
| prompt | a `string` (wrapped as a `User` `TextContent` message), a `PromptMessage`, or a list of `PromptMessage` |
| resource | a `string` (wrapped as `TextResourceContents` bound to the URI), a `ResourceContents`, or a list of `ResourceContents` |
| completion | a list of strings (wrapped as the `values` of a `CompleteResult`), so returning the full `CompleteResult` is only needed for `total` or `hasMore` |

## Server identity precedence

When both `#[AsServer]` and an explicit `setServerInfo()` / `setInstructions()` are present, the explicit
call wins per field and the attribute fills only the gaps it left, regardless of call order. So
`setServerInfo(name: 'x', version: '1.0.0')` alongside an `#[AsServer]` that also carries a `title` and
`description` keeps your name and version while picking up the title and description from the attribute.

At most one registered source may declare `#[AsServer]`. A second one throws
`DuplicateServerMetadataException`. The setters keep their normal last-call-wins behaviour. Only conflicting
attributes are rejected.

## Limitations

- Only public methods are scanned.
- Tool arguments are typed by the validated `inputSchema`, but prompt arguments and resource URI variables
  arrive as strings. A prompt, resource, or resource-template parameter that a string cannot satisfy throws
  `UnsupportedParameterTypeException` at registration.
- Variadic parameters are accepted only on tools. On prompts and resources they throw
  `UnsupportedVariadicParameterException`.
- Object (DTO) expansion is one level deep and tool-only. A constructor parameter typed as another class, a
  list of objects, an interface, or an abstract class is not expanded and throws.
- There is no filesystem auto-discovery and no class-level handler backend. `register()` takes explicit
  source objects.
- Only the per-feature listings are discoverable. Singleton infrastructure, such as the
  [subscription store](server/subscriptions.md), the logger, and the schema validator, is registered through
  the builder's `set*` methods.

## See also

- **[examples/attribute-discovery.php](../examples/attribute-discovery.php)**: a runnable server built this way.
- **[conformance/EverythingServer.php](../conformance/EverythingServer.php)**: the largest one, and the
  only one held to the spec by an outside referee. Every capability the MCP conformance suite exercises
  is an attribute-marked method on a single class, including the `#[InputSchema(definition: ...)]`
  escape hatch for a hand-written JSON Schema 2020-12 document.
- **[Server API](server.md)**: the manual `add*` / `set*` registration these attributes build on.
- **[Design rationale](design-rationale.md)**: why explicit composition is the substrate and attribute discovery is layered on top.
