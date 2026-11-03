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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\MessageDiscriminator;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MessageDiscriminator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MessageDiscriminatorTest extends AbstractMcpTestCase
{
    public function testReadTypeReturnsTypeValue(): void
    {
        $type = MessageDiscriminator::readType(['type' => 'text', 'text' => 'hello'], 'prompt message "content"');

        self::assertSame('text', $type);
    }

    public function testReadTypeRejectsMissingType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('prompt message "content" data missing "type".');

        MessageDiscriminator::readType(['text' => 'hello'], 'prompt message "content"');
    }

    public function testReadTypeRejectsNonStringType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params.ref" "type" must be a string, int given.');

        MessageDiscriminator::readType(['type' => 1], '"params.ref"');
    }

    public function testUnknownTypeFormatsExceptionWithAllowedValues(): void
    {
        $exception = MessageDiscriminator::buildUnknownTypeError(
            '"params.ref"',
            ['ref/prompt', 'ref/resource'],
            'unknown',
        );

        self::assertSame(
            '"params.ref" "type" must be one of "ref/prompt", "ref/resource", \'unknown\' given.',
            $exception->getMessage(),
        );
    }

    public function testUnknownTypeWithSingleAllowedValue(): void
    {
        $exception = MessageDiscriminator::buildUnknownTypeError('Foo', ['only'], 'other');

        self::assertSame(
            'Foo "type" must be one of "only", \'other\' given.',
            $exception->getMessage(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param class-string         $expectedClass
     */
    #[DataProvider('provideDecodeContentBlockDispatchesByTypeCases')]
    public function testDecodeContentBlockDispatchesByType(array $payload, string $expectedClass): void
    {
        self::assertInstanceOf(
            $expectedClass,
            MessageDiscriminator::decodeContentBlock($payload, 'prompt message "content"'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string}>
     */
    public static function provideDecodeContentBlockDispatchesByTypeCases(): iterable
    {
        yield 'text' => [['type' => 'text', 'text' => 'hi'], TextContent::class];

        yield 'image' => [['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'], ImageContent::class];

        yield 'audio' => [['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'], AudioContent::class];

        yield 'resource_link' => [
            ['type' => 'resource_link', 'name' => 'doc', 'uri' => 'file:///x'],
            ResourceLink::class,
        ];

        yield 'embedded resource' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hi']],
            EmbeddedResource::class,
        ];
    }

    public function testDecodeContentBlockRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'prompt message "content" "type" must be one of "text", "image", "audio", "resource_link", "resource", \'unknown\' given.',
        );

        MessageDiscriminator::decodeContentBlock(['type' => 'unknown'], 'prompt message "content"');
    }

    public function testDecodeContentBlockPropagatesContextToReadType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('CallToolResult content data missing "type".');

        MessageDiscriminator::decodeContentBlock(['text' => 'oops'], 'CallToolResult content');
    }

    public function testDecodeResourceContentsDispatchesToTextResourceContents(): void
    {
        $contents = MessageDiscriminator::decodeResourceContents(
            ['uri' => 'file:///x', 'text' => 'hello'],
            'embedded resource resource',
        );

        self::assertInstanceOf(TextResourceContents::class, $contents);
        self::assertSame('hello', $contents->text);
    }

    public function testDecodeResourceContentsDispatchesToBlobResourceContents(): void
    {
        $contents = MessageDiscriminator::decodeResourceContents(
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8='],
            'embedded resource resource',
        );

        self::assertInstanceOf(BlobResourceContents::class, $contents);
        self::assertSame('aGVsbG8=', $contents->blob);
    }

    public function testDecodeResourceContentsRejectsBothTextAndBlob(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('embedded resource resource data must not have both "text" and "blob".');

        MessageDiscriminator::decodeResourceContents(
            ['uri' => 'file:///x', 'text' => 'hello', 'blob' => 'aGVsbG8='],
            'embedded resource resource',
        );
    }

    public function testDecodeResourceContentsRejectsMissingDiscriminator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('ReadResourceResult contents data must have either "text" or "blob".');

        MessageDiscriminator::decodeResourceContents(['uri' => 'file:///x'], 'ReadResourceResult contents');
    }

    public function testDecodeInputRequestDispatchesAnElicitRequest(): void
    {
        $request = MessageDiscriminator::decodeInputRequest([
            'method' => 'elicitation/create',
            'params' => [
                'mode' => 'form',
                'message' => 'Confirm?',
                'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
        ]);

        self::assertInstanceOf(ElicitRequest::class, $request);
    }

    public function testDecodeInputRequestRejectsAMissingMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry is missing the required "method" key.');

        MessageDiscriminator::decodeInputRequest(['params' => []]);
    }

    public function testDecodeInputRequestRejectsAnUnsupportedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'each "result.inputRequests" entry must use a supported input-request method, \'sampling/createMessage\' given.',
        );

        MessageDiscriminator::decodeInputRequest(['method' => 'sampling/createMessage']);
    }
}
