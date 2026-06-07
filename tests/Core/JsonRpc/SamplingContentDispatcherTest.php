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
use Nexus\Mcp\Core\JsonRpc\SamplingContentDispatcher;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Sampling\ToolResultContent;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SamplingContentDispatcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SamplingContentDispatcherTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @param class-string         $expectedClass
     */
    #[DataProvider('provideFromArrayDispatchesByTypeCases')]
    public function testFromArrayDispatchesByType(array $payload, string $expectedClass): void
    {
        self::assertInstanceOf($expectedClass, SamplingContentDispatcher::fromArray($payload, 'sampling message content'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string}>
     */
    public static function provideFromArrayDispatchesByTypeCases(): iterable
    {
        yield 'text' => [['type' => 'text', 'text' => 'hi'], TextContent::class];

        yield 'image' => [['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'], ImageContent::class];

        yield 'audio' => [['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'], AudioContent::class];

        yield 'tool_use' => [
            ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
            ToolUseContent::class,
        ];

        yield 'tool_result' => [
            ['type' => 'tool_result', 'toolUseId' => 'tu-1', 'content' => []],
            ToolResultContent::class,
        ];
    }

    public function testFromArrayRejectsUnknownType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('sampling message content "type" must be one of "text", "image", "audio", "tool_use", "tool_result", \'resource_link\' given.');

        SamplingContentDispatcher::fromArray(['type' => 'resource_link'], 'sampling message content');
    }

    public function testFromArrayPropagatesContextToReadType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result" content data missing "type".');

        SamplingContentDispatcher::fromArray(['text' => 'oops'], '"result" content');
    }
}
