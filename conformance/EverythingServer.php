<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Attribute\AsCompletion;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Attribute\InputSchema;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\MutablePromptStoreInterface;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\MutableToolStoreInterface;

use function Amp\delay;

/**
 * The fixture the conformance referee drives.
 *
 * Every capability is a public method carrying a discovery attribute, so
 * `ServerBuilder::register()` derives each `inputSchema` from the parameter
 * types and `@param` lines. Tool names are pinned explicitly because the
 * referee looks them up by the names the canonical fixture uses.
 */
#[AsServer(
    name: 'mcp-conformance-test-server',
    version: '1.0.0',
    description: 'The Nexus MCP SDK fixture for the MCP conformance suite.',
)]
final class EverythingServer
{
    /**
     * A 1x1 red PNG.
     */
    private const string RED_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * A minimal silent WAV.
     */
    private const string SILENT_WAV = 'UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';

    private int $mutations = 0;
    private ?MutableToolStoreInterface $toolStore = null;
    private ?MutablePromptStoreInterface $promptStore = null;

    /**
     * Hands the fixture the stores `build()` assembled, so the diagnostic triggers below can mutate the
     * same listings the handlers serve.
     */
    public function useStores(MutableToolStoreInterface $tools, MutablePromptStoreInterface $prompts): void
    {
        $this->toolStore = $tools;
        $this->promptStore = $prompts;
    }

    /**
     * The referee drives these to prove a mutated listing reaches an open `subscriptions/listen` stream.
     * They are the one place the fixture reaches for the stores rather than declaring through attributes.
     */
    #[AsTool(name: 'test_trigger_tool_change', description: 'Adds a tool, changing the tool list.')]
    public function triggerToolChange(): string
    {
        $name = sprintf('generated_tool_%d', ++$this->mutations);
        $this->toolStore?->addTool(
            new Tool(name: $name, description: 'Generated to change the tool list.', inputSchema: ['type' => 'object']),
            new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(
                content: [new TextContent(text: 'generated')],
            )),
        );

        return $name;
    }

    #[AsTool(name: 'test_trigger_prompt_change', description: 'Adds a prompt, changing the prompt list.')]
    public function triggerPromptChange(): string
    {
        $name = sprintf('generated_prompt_%d', ++$this->mutations);
        $this->promptStore?->addPrompt(
            new Prompt(name: $name, description: 'Generated to change the prompt list.'),
            new ClosurePromptRenderer(static fn(?array $arguments, ServerContext $context): GetPromptResult => new GetPromptResult(
                messages: [],
            )),
        );

        return $name;
    }

    #[AsTool(name: 'test_simple_text', description: 'Returns a simple text response.')]
    public function simpleText(): string
    {
        return 'This is a simple text response for testing.';
    }

    #[AsTool(name: 'test_image_content', description: 'Returns an image content block.')]
    public function imageContent(): ImageContent
    {
        return new ImageContent(data: self::RED_PIXEL_PNG, mimeType: 'image/png');
    }

    #[AsTool(name: 'test_audio_content', description: 'Returns an audio content block.')]
    public function audioContent(): AudioContent
    {
        return new AudioContent(data: self::SILENT_WAV, mimeType: 'audio/wav');
    }

    #[AsTool(name: 'test_embedded_resource', description: 'Returns an embedded resource content block.')]
    public function embeddedResource(): EmbeddedResource
    {
        return new EmbeddedResource(resource: new TextResourceContents(
            uri: 'test://embedded-resource',
            text: 'This is an embedded resource content.',
            mimeType: 'text/plain',
        ));
    }

    /**
     * @return list<EmbeddedResource|ImageContent|TextContent>
     */
    #[AsTool(name: 'test_multiple_content_types', description: 'Returns text, image, and resource content blocks in one result.')]
    public function multipleContentTypes(): array
    {
        return [
            new TextContent(text: 'Multiple content types test:'),
            new ImageContent(data: self::RED_PIXEL_PNG, mimeType: 'image/png'),
            new EmbeddedResource(resource: new TextResourceContents(
                uri: 'test://mixed-content-resource',
                text: json_encode(['test' => 'data', 'value' => 123], \JSON_THROW_ON_ERROR),
                mimeType: 'application/json',
            )),
        ];
    }

    /**
     * The referee asserts the SDK converts the throw into a `CallToolResult`
     * with `isError: true` rather than a JSON-RPC error.
     */
    #[AsTool(name: 'test_error_handling', description: 'Always throws, testing tool-error conversion.')]
    public function errorHandling(): never
    {
        throw new RuntimeException('This tool intentionally returns an error for testing');
    }

    #[AsTool(name: 'test_tool_with_progress', description: 'Reports progress while it works.')]
    public function toolWithProgress(ServerContext $context): string
    {
        foreach ([0.0, 50.0, 100.0] as $step => $progress) {
            if ($step > 0) {
                delay(0.05);
            }

            $context->reportProgress(progress: $progress, total: 100.0);
        }

        return 'Completed 3 progress steps.';
    }

    /**
     * The `server-stateless` scenario calls this to prove the server refuses work
     * needing a client capability the client never declared.
     */
    #[AsTool(name: 'test_missing_capability', description: 'Needs the sampling client capability.')]
    public function missingCapability(ServerContext $context): string
    {
        // Sampling is outside the capability set this SDK models, so it arrives as an extra.
        if (! array_key_exists('sampling', $context->meta->clientCapabilities->extras)) {
            throw new MissingRequiredClientCapabilityException(
                new ClientCapabilities(extras: ['sampling' => []]),
                $context->requestId,
            );
        }

        return 'Sampling is available.';
    }

    /**
     * The `http-custom-header-server-validation` scenario mirrors `message` into
     * `Mcp-Param-Message` and asserts the server checks the two agree. `x-mcp-header`
     * sits on the property rather than the tool, so the schema is supplied rather
     * than derived from the signature.
     *
     * @param string $message Echoed back, and mirrored into `Mcp-Param-Message`.
     */
    #[AsTool(name: 'test_custom_header_param', description: 'Echoes a parameter that is also carried as a custom header.')]
    #[InputSchema(definition: [
        'type' => 'object',
        'properties' => [
            'message' => [
                'type' => 'string',
                'description' => 'Echoed back, and mirrored into `Mcp-Param-Message`.',
                'x-mcp-header' => 'Message',
            ],
        ],
        'required' => ['message'],
    ])]
    public function customHeaderParam(string $message): string
    {
        return sprintf('Received: %s', $message);
    }

    /**
     * The `server-stateless` scenario opens this call's response stream and asserts
     * the server sends nothing on it but that call's own frames.
     */
    #[AsTool(name: 'test_streaming_elicitation', description: 'Emits progress frames so the response stream stays open for inspection.')]
    public function streamingElicitation(ServerContext $context): string
    {
        $context->reportProgress(progress: 1.0, total: 2.0, message: 'Working.');
        $context->reportProgress(progress: 2.0, total: 2.0, message: 'Done.');

        return 'Streamed.';
    }

    /**
     * The `server-stateless` scenario calls this without a `logLevel` in the request
     * `_meta` and asserts no log notification comes back. The SDK emits none at all,
     * logging having been removed at the 2026-07-28 cut, so the requirement holds
     * trivially. The tool exists so the referee can assert that rather than skip.
     */
    #[AsTool(name: 'test_logging_tool', description: 'Would log if the request asked for logs.')]
    public function loggingTool(ServerContext $context): string
    {
        return sprintf('Requested log level: %s.', $context->meta->logLevel->value ?? 'none');
    }

    /**
     * Advertises a hand-written JSON Schema 2020-12 document. The scanner derives a
     * schema from the signature for every other tool, but this scenario asserts the
     * server preserves `$schema`, `$defs`, and `additionalProperties` verbatim, so
     * the schema is supplied rather than derived.
     *
     * @param null|array<string, mixed> $arguments
     */
    #[AsTool(name: 'json_schema_2020_12_tool', description: 'Tool with JSON Schema 2020-12 features')]
    #[InputSchema(definition: [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
        '$defs' => [
            'address' => [
                '$anchor' => 'addressDef',
                'type' => 'object',
                'properties' => [
                    'street' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                ],
            ],
        ],
        'properties' => [
            'name' => ['type' => 'string'],
            'address' => ['$ref' => '#/$defs/address'],
            'contactMethod' => ['type' => 'string', 'enum' => ['phone', 'email']],
            'phone' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ],
        'allOf' => [
            ['anyOf' => [['required' => ['phone']], ['required' => ['email']]]],
        ],
        'if' => [
            'properties' => ['contactMethod' => ['const' => 'phone']],
            'required' => ['contactMethod'],
        ],
        'then' => ['required' => ['phone']],
        'else' => ['required' => ['email']],
        'additionalProperties' => false,
    ])]
    public function jsonSchema202012Tool(?array $arguments): string
    {
        $name = is_string($arguments['name'] ?? null) ? $arguments['name'] : 'unnamed';

        return sprintf('Received "%s".', $name);
    }

    /**
     * @param non-empty-string $uri
     */
    #[AsResource(uri: 'test://static-text', name: 'static-text', description: 'A static text resource.', mimeType: 'text/plain')]
    public function staticText(string $uri): TextResourceContents
    {
        return new TextResourceContents(uri: $uri, text: 'This is the content of the static text resource.', mimeType: 'text/plain');
    }

    /**
     * @param non-empty-string $uri
     */
    #[AsResource(uri: 'test://static-binary', name: 'static-binary', description: 'A static binary resource.', mimeType: 'image/png')]
    public function staticBinary(string $uri): BlobResourceContents
    {
        return new BlobResourceContents(uri: $uri, blob: self::RED_PIXEL_PNG, mimeType: 'image/png');
    }

    /**
     * @param string           $id  The identifier captured from the URI.
     * @param non-empty-string $uri
     */
    #[AsResourceTemplate(uriTemplate: 'test://template/{id}/data', name: 'template', description: 'A templated resource addressed by id.', mimeType: 'application/json')]
    public function templatedResource(string $uri, string $id): TextResourceContents
    {
        return new TextResourceContents(
            uri: $uri,
            text: json_encode(
                ['id' => $id, 'templateTest' => true, 'data' => sprintf('Data for ID: %s', $id)],
                \JSON_THROW_ON_ERROR,
            ),
            mimeType: 'application/json',
        );
    }

    /**
     * Both prompt arguments and the template variable complete from the same
     * canned list the referee expects to see filtered.
     */
    #[AsCompletion(argument: 'arg1', prompt: 'test_prompt_with_arguments')]
    #[AsCompletion(argument: 'arg2', prompt: 'test_prompt_with_arguments')]
    #[AsCompletion(argument: 'id', uriTemplate: 'test://template/{id}/data')]
    public function completeArgument(string $value): CompleteResult
    {
        $matches = [];

        foreach (['alpha', 'beta', 'gamma', 'delta'] as $candidate) {
            if ('' === $value || str_starts_with($candidate, $value)) {
                $matches[] = $candidate;
            }
        }

        return new CompleteResult(completion: ['values' => $matches, 'total' => count($matches), 'hasMore' => false]);
    }

    #[AsPrompt(name: 'test_simple_prompt', description: 'A prompt with no arguments.')]
    public function simplePrompt(): string
    {
        return 'This is a simple prompt for testing.';
    }

    /**
     * @param string $arg1 First test argument.
     * @param string $arg2 Second test argument.
     */
    #[AsPrompt(name: 'test_prompt_with_arguments', description: 'A prompt with required arguments.')]
    public function promptWithArguments(string $arg1, string $arg2): string
    {
        return sprintf('Prompt with arguments: arg1=\'%s\', arg2=\'%s\'', $arg1, $arg2);
    }

    /**
     * @param non-empty-string $resourceUri URI of the resource to embed.
     */
    #[AsPrompt(name: 'test_prompt_with_embedded_resource', description: 'A prompt carrying an embedded resource.')]
    public function promptWithEmbeddedResource(string $resourceUri): GetPromptResult
    {
        return new GetPromptResult(messages: [
            new PromptMessage(
                role: Role::User,
                content: new EmbeddedResource(resource: new TextResourceContents(
                    uri: $resourceUri,
                    text: 'Embedded resource content for testing.',
                    mimeType: 'text/plain',
                )),
            ),
            new PromptMessage(
                role: Role::User,
                content: new TextContent(text: 'Please process the embedded resource above.'),
            ),
        ]);
    }

    #[AsPrompt(name: 'test_prompt_with_image', description: 'A prompt carrying an image.')]
    public function promptWithImage(): GetPromptResult
    {
        return new GetPromptResult(messages: [
            new PromptMessage(
                role: Role::User,
                content: new ImageContent(data: self::RED_PIXEL_PNG, mimeType: 'image/png'),
            ),
            new PromptMessage(
                role: Role::User,
                content: new TextContent(text: 'Please analyze the image above.'),
            ),
        ]);
    }
}
