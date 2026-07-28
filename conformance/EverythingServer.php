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

use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Attribute\InputSchema;
use Nexus\Mcp\Server\ServerContext;

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

    #[AsTool(name: 'test_simple_text', description: 'Returns a simple text response.')]
    public function simpleText(): string
    {
        return 'Hello from the Nexus MCP SDK conformance server!';
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
            text: 'This is an embedded resource.',
            mimeType: 'text/plain',
        ));
    }

    /**
     * @return list<AudioContent|EmbeddedResource|ImageContent|TextContent>
     */
    #[AsTool(name: 'test_multiple_content_types', description: 'Returns text, image, audio, and resource content blocks in one result.')]
    public function multipleContentTypes(): array
    {
        return [
            new TextContent(text: 'This response contains multiple content types.'),
            new ImageContent(data: self::RED_PIXEL_PNG, mimeType: 'image/png'),
            new AudioContent(data: self::SILENT_WAV, mimeType: 'audio/wav'),
            new EmbeddedResource(resource: new TextResourceContents(
                uri: 'test://mixed-content-resource',
                text: 'Resource content within a mixed result.',
                mimeType: 'text/plain',
            )),
        ];
    }

    #[AsTool(name: 'test_error_handling', description: 'Always returns a tool error.')]
    public function errorHandling(): CallToolResult
    {
        return new CallToolResult(
            content: [new TextContent(text: 'This tool intentionally failed.')],
            isError: true,
        );
    }

    /**
     * @param int $steps How many progress notifications to emit.
     */
    #[AsTool(name: 'test_tool_with_progress', description: 'Reports progress while it works.')]
    public function toolWithProgress(ServerContext $context, int $steps = 3): string
    {
        for ($step = 1; $step <= $steps; ++$step) {
            $context->reportProgress(
                progress: (float) $step,
                total: (float) $steps,
                message: sprintf('Step %d of %d.', $step, $steps),
            );
        }

        return sprintf('Completed %d steps.', $steps);
    }

    /**
     * The `server-stateless` scenario calls this to prove the server refuses work
     * needing a client capability the client never declared. The SDK has no way to
     * raise `-32021` from a handler yet, so this reports what it saw and the two
     * checks that assert the rejection stay baselined.
     */
    #[AsTool(name: 'test_missing_capability', description: 'Needs the sampling client capability.')]
    public function missingCapability(ServerContext $context): string
    {
        $declared = array_keys($context->meta->clientCapabilities->toArray());

        return sprintf('Client declared: %s.', [] === $declared ? 'nothing' : implode(', ', $declared));
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
        return sprintf('Requested log level: %s.', $context->meta->logLevel?->value ?? 'none');
    }

    /**
     * Advertises a hand-written JSON Schema 2020-12 document. The scanner derives a
     * schema from the signature for every other tool, but this scenario asserts the
     * server preserves `$schema`, `$defs`, and `additionalProperties` verbatim, so
     * the schema is supplied rather than derived.
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

    #[AsResource(uri: 'test://static-text', name: 'static-text', description: 'A static text resource.', mimeType: 'text/plain')]
    public function staticText(string $uri): TextResourceContents
    {
        return new TextResourceContents(uri: $uri, text: 'This is a static text resource.', mimeType: 'text/plain');
    }

    #[AsResource(uri: 'test://static-binary', name: 'static-binary', description: 'A static binary resource.', mimeType: 'image/png')]
    public function staticBinary(string $uri): BlobResourceContents
    {
        return new BlobResourceContents(uri: $uri, blob: self::RED_PIXEL_PNG, mimeType: 'image/png');
    }

    /**
     * @param string $id The identifier captured from the URI.
     */
    #[AsResourceTemplate(uriTemplate: 'test://template/{id}/data', name: 'template', description: 'A templated resource addressed by id.', mimeType: 'text/plain')]
    public function templatedResource(string $uri, string $id): TextResourceContents
    {
        return new TextResourceContents(uri: $uri, text: sprintf('Template data for id "%s".', $id), mimeType: 'text/plain');
    }

    #[AsPrompt(name: 'test_simple_prompt', description: 'A prompt with no arguments.')]
    public function simplePrompt(): string
    {
        return 'This is a simple prompt with no arguments.';
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

    #[AsPrompt(name: 'test_prompt_with_embedded_resource', description: 'A prompt carrying an embedded resource.')]
    public function promptWithEmbeddedResource(): GetPromptResult
    {
        return new GetPromptResult(messages: [
            new PromptMessage(
                role: Role::User,
                content: new EmbeddedResource(resource: new TextResourceContents(
                    uri: 'test://static-text',
                    text: 'This is a static text resource.',
                    mimeType: 'text/plain',
                )),
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
        ]);
    }
}
