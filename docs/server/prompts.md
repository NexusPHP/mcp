# Prompts

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
