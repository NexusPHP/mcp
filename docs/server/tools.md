# Tools

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

## Attribute sugar

`#[AsTool]` marks a method as a tool, discovered through the same
[`ServerBuilder::register()`](../attribute-discovery.md) walk as the other attributes. The `inputSchema`
is generated from the parameter types and `@param` docblocks, a `ServerContext` parameter is injected and
left out of the schema, and the name falls back to the method name:

```php
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\ServerContext;

final class DocsTools
{
    /**
     * @param string $query Text to search for.
     */
    #[AsTool(description: 'Searches the docs index.')]
    public function search_docs(string $query, ServerContext $context): string
    {
        return "Results for {$query}";
    }
}
```

The method returns a full `CallToolResult` or a shorthand the SDK adapts: a string (wrapped as
`TextContent`), a content block or a list of them, or an array treated as `structuredContent`. See
[Attribute discovery](../attribute-discovery.md) for the schema-inference rules, per-parameter
`#[InputSchema(...)]` overrides, variadics, and object expansion.

## Result content types

`CallToolResult::$content` is a list of content blocks, and the five block types compose freely in one
result:

```php
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

return new CallToolResult(content: [
    new TextContent(text: 'The chart, its narration, and the raw numbers:'),
    new ImageContent(data: base64_encode($png), mimeType: 'image/png'),
    new AudioContent(data: base64_encode($wav), mimeType: 'audio/wav'),
    new EmbeddedResource(resource: new TextResourceContents(uri: 'data://chart.csv', text: $csv)),
    new ResourceLink(name: 'full-report', uri: 'reports://2026/q3'),
]);
```

`ImageContent` and `AudioContent` carry base64-encoded bytes plus a MIME type. `EmbeddedResource` inlines a
resource's contents (`TextResourceContents` or `BlobResourceContents`) into the result, while `ResourceLink`
names a resource by URI without inlining it, so the client can fetch it through `resources/read` when it
wants the bytes. The same block types appear in [prompt messages](prompts.md#message-content-types).

## Structured content

A tool may return `structuredContent` instead of, or alongside, its `content` blocks. It may be any
JSON value the tool's `outputSchema` accepts:

```php
return new CallToolResult(
    content: [],
    structuredContent: ['temperature' => 22.5, 'unit' => 'celsius'],
);

// An `outputSchema` of `{"type": "array"}` takes a list, and a scalar schema takes a scalar.
return new CallToolResult(content: [], structuredContent: [['id' => '1'], ['id' => '2']]);
```

A discovered `#[AsTool]` method cannot express the list form: a list return is read as content blocks,
so a tool with an array `outputSchema` has to build its `CallToolResult` explicitly, as above.

PHP spells an empty object and an empty array the same, so an empty `structuredContent` is validated as
whichever the declared `outputSchema` asks for. On the encoding side it always emits `[]`, which a peer
re-validating against `{"type": "object"}` will refuse. A tool whose `outputSchema` is `{"type": "null"}`
is not supported: `null` structured content is indistinguishable from none, so every call fails as
missing structured content.

For backwards compatibility, the spec recommends that a tool returning `structuredContent` also return
the serialised JSON in a `TextContent` block. When the executor leaves `content` empty, the handler adds
that block for you. Provide your own `content` to keep control of the text representation. A non-empty
`content` list is passed through untouched.

## Schema validation

A tool call is validated against the tool's schemas on the way in and on the way out:

- The call `arguments` are validated against the tool's `inputSchema`. A non-conforming payload fails the
  call with a JSON-RPC `InvalidParams` error before the executor runs.
- When the tool declares an `outputSchema`, a (non-error) result must carry `structuredContent`
  conforming to it. A non-conforming or missing one is logged server-side and surfaced to the client as
  a generic error result, so malformed structured data is never sent.

Validation is backed by [opis/json-schema](https://github.com/opis/json-schema) (JSON Schema draft
2020-12) by default. The `[]`-versus-`{}` ambiguity exists inside a schema too: `json_decode(..., true)`
renders the always-valid `{}` as PHP `[]`, so the default validator restores it in every sub-schema
position (a `properties` value, `items`, an `allOf` element, and the rest) before validating. Supply your
own engine by implementing `SchemaValidatorInterface` and registering it with
`ServerBuilder::setSchemaValidator()`.

```php
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;

$server = (new ServerBuilder())
    ->setSchemaValidator($myValidator) // any SchemaValidatorInterface
    // ...
    ->build()
;
```
