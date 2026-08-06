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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\JsonRpc\ContentBlockDispatcher;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ContentBlockDispatcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ContentBlockDispatcherTest extends AbstractMcpTestCase
{
    /**
     * @param array<string, mixed> $payload
     * @param class-string         $expectedClass
     */
    #[DataProvider('provideFromArrayDispatchesByTypeCases')]
    public function testFromArrayDispatchesByType(array $payload, string $expectedClass): void
    {
        self::assertInstanceOf($expectedClass, ContentBlockDispatcher::fromArray($payload, 'prompt message "content"'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string}>
     */
    public static function provideFromArrayDispatchesByTypeCases(): iterable
    {
        yield 'text' => [['type' => 'text', 'text' => 'hi'], TextContent::class];

        yield 'image' => [['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'], ImageContent::class];

        yield 'audio' => [['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'], AudioContent::class];

        yield 'resource_link' => [['type' => 'resource_link', 'name' => 'doc', 'uri' => 'file:///x'], ResourceLink::class];

        yield 'embedded resource' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hi']],
            EmbeddedResource::class,
        ];
    }

    public function testFromArrayRejectsUnknownType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('prompt message "content" "type" must be one of "text", "image", "audio", "resource_link", "resource", \'unknown\' given.');

        ContentBlockDispatcher::fromArray(['type' => 'unknown'], 'prompt message "content"');
    }

    public function testFromArrayPropagatesContextToReadType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('CallToolResult content data missing "type".');

        ContentBlockDispatcher::fromArray(['text' => 'oops'], 'CallToolResult content');
    }
}
