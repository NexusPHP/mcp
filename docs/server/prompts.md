# Prompts

How to expose a prompt: pair a spec `Prompt` definition with the renderer that serves its
`prompts/get`, and register both with `addPrompt()`.

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

## Attribute sugar

`#[AsPrompt]` marks a method as a prompt, discovered through the same
[`ServerBuilder::register()`](../attribute-discovery.md) walk as the other attributes. Each parameter
becomes a prompt argument, required when it has no default, with its `@param` text as the description,
and the call's arguments are bound back to the parameters by name:

```php
use Nexus\Mcp\Server\Attribute\AsPrompt;

final class SummaryPrompts
{
    /**
     * @param string $tone The desired tone.
     */
    #[AsPrompt(name: 'summarise', description: 'Summarises the user input.')]
    public function summarise(string $tone = 'neutral'): string
    {
        return "Summarise the following in a {$tone} tone: ...";
    }
}
```

A string return becomes a single `User` text message. A `PromptMessage`, a list of them, or a full
`GetPromptResult` pass through. Prompt arguments arrive as strings, so every non-injected parameter must
accept one (`string`, an enum hydrated from the value, or untyped).
[Attribute discovery](../attribute-discovery.md) has the full binding rules.

## Message content types

`PromptMessage::$content` takes any single content block, so a prompt can carry images and embedded
resources alongside text:

```php
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

return new GetPromptResult(messages: [
    new PromptMessage(role: Role::User, content: new TextContent(text: 'Describe this diagram:')),
    new PromptMessage(role: Role::User, content: new ImageContent(data: base64_encode($png), mimeType: 'image/png')),
    new PromptMessage(role: Role::User, content: new EmbeddedResource(
        resource: new TextResourceContents(uri: 'guides://style.md', text: $styleGuide),
    )),
]);
```

Each message holds exactly one block. To pair a caption with an image, send two messages, as above. The
block types are the same five a [tool result](tools.md#result-content-types) carries.
