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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CallToolResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CallToolResultTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $result = new CallToolResult([]);

        self::assertSame([], $result->content);
        self::assertNull($result->structuredContent);
        self::assertNull($result->isError);
        self::assertNull($result->meta);
    }

    public function testConstructionWithAllFields(): void
    {
        $result = new CallToolResult(
            content: [new TextContent('hello')],
            structuredContent: ['lines' => 42],
            isError: false,
            meta: new MetaObject(['vendor' => 'x']),
        );

        self::assertCount(1, $result->content);
        self::assertSame(['lines' => 42], $result->structuredContent);
        self::assertFalse($result->isError);
    }

    public function testToArrayMinimal(): void
    {
        $result = new CallToolResult([]);

        self::assertSame(['content' => []], $result->toArray());
    }

    public function testToArrayWithMixedContent(): void
    {
        $result = new CallToolResult([
            new TextContent('hi'),
            new ImageContent('aGVsbG8=', 'image/png'),
        ]);

        self::assertSame(
            [
                'content' => [
                    ['text' => 'hi', 'type' => 'text'],
                    ['data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'type' => 'image'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new CallToolResult(
            content: [new TextContent('hi')],
            structuredContent: ['lines' => 42],
            isError: false,
            meta: new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'content' => [['text' => 'hi', 'type' => 'text']],
                'structuredContent' => ['lines' => 42],
                'isError' => false,
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new CallToolResult([new TextContent('hi')]);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayDispatchesContentBlocks(): void
    {
        $result = CallToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
                ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'],
            ],
        ]);

        self::assertCount(2, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertInstanceOf(AudioContent::class, $result->content[1]);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CallToolResult(
            content: [new TextContent('hi')],
            structuredContent: ['lines' => 42],
            isError: true,
            meta: new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = CallToolResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CallToolResult content must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new CallToolResult([5 => new TextContent('x')]);
    }

    public function testConstructorRejectsNonContentBlockEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new CallToolResult([42]);
    }

    public function testConstructorRejectsListKeyedStructuredContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CallToolResult structuredContent must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new CallToolResult([], ['v1', 'v2']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        CallToolResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing content' => [
            [],
            'CallToolResult wire data missing "content".',
        ];

        yield 'content not an array' => [
            ['content' => 'oops'],
            'CallToolResult wire "content" must be a list, string given.',
        ];

        yield 'content entry not an object' => [
            ['content' => ['oops']],
            'CallToolResult wire content entry must be an object, string given.',
        ];

        yield 'content entry list-keyed' => [
            ['content' => [['x']]],
            'CallToolResult wire content entry must be a string-keyed object.',
        ];

        yield 'content entry missing type' => [
            ['content' => [['text' => 'x']]],
            'CallToolResult content wire data missing "type".',
        ];

        yield 'content entry unknown type' => [
            ['content' => [['type' => 'unknown']]],
            'CallToolResult content wire "type" must be one of "text", "image", "audio", "resource_link", "resource"; "unknown" given.',
        ];

        yield 'structuredContent not an object' => [
            ['content' => [], 'structuredContent' => 'oops'],
            'CallToolResult wire "structuredContent" must be an object, string given.',
        ];

        yield 'structuredContent list-keyed' => [
            ['content' => [], 'structuredContent' => ['x']],
            'CallToolResult wire "structuredContent" must be a string-keyed object.',
        ];

        yield 'isError not a bool' => [
            ['content' => [], 'isError' => 'oops'],
            'CallToolResult wire "isError" must be a bool or null, string given.',
        ];

        yield '_meta not an object' => [
            ['content' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];
    }
}
