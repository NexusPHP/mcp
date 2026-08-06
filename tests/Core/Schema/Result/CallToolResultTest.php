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
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CallToolResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CallToolResultTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $result = new CallToolResult(content: []);

        self::assertSame([], $result->content);
        self::assertNull($result->structuredContent);
        self::assertNull($result->isError);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionWithAllFields(): void
    {
        $result = new CallToolResult(
            content: [new TextContent(text: 'hello')],
            structuredContent: ['lines' => 42],
            isError: false,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertCount(1, $result->content);
        self::assertSame(['lines' => 42], $result->structuredContent);
        self::assertFalse($result->isError);
    }

    public function testToArrayMinimal(): void
    {
        $result = new CallToolResult(content: []);

        self::assertSame(['resultType' => 'complete', 'content' => []], $result->toArray());
    }

    public function testToArrayWithMixedContent(): void
    {
        $result = new CallToolResult(content: [
            new TextContent(text: 'hi'),
            new ImageContent(data: 'aGVsbG8=', mimeType: 'image/png'),
        ]);

        self::assertSame(
            [
                'resultType' => 'complete',
                'content' => [
                    ['text' => 'hi', 'type' => 'text'],
                    ['data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'type' => 'image'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new CallToolResult(
            content: [new TextContent(text: 'hi')],
            structuredContent: ['lines' => 42],
            isError: false,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(
            ['_meta' => ['replaced' => true]] + $result->toArray(),
            $rebuilt->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new CallToolResult(
            content: [new TextContent(text: 'hi')],
            structuredContent: ['lines' => 42],
            isError: false,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'content' => [['text' => 'hi', 'type' => 'text']],
                'structuredContent' => ['lines' => 42],
                'isError' => false,
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new CallToolResult(content: [new TextContent(text: 'hi')]);

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
            content: [new TextContent(text: 'hi')],
            structuredContent: ['lines' => 42],
            isError: true,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = CallToolResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.content" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new CallToolResult(content: [5 => new TextContent(text: 'x')]);
    }

    public function testConstructorRejectsNonContentBlockEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new CallToolResult(content: [42]);
    }

    public function testConstructorRejectsListKeyedStructuredContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.structuredContent" must be a string-keyed map or null.');

        // @phpstan-ignore argument.type
        new CallToolResult(content: [], structuredContent: ['v1', 'v2']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CallToolResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing content' => [
            [],
            '"result" is missing the required "content" key.',
        ];

        yield 'content not an array' => [
            ['content' => 'oops'],
            '"result.content" must be a list, string given.',
        ];

        yield 'content entry not an object' => [
            ['content' => ['oops']],
            'each "result.content" must be an object, string given.',
        ];

        yield 'content entry list-keyed' => [
            ['content' => [['x']]],
            'each "result.content" must be a string-keyed object.',
        ];

        yield 'content entry missing type' => [
            ['content' => [['text' => 'x']]],
            'CallToolResult content data missing "type".',
        ];

        yield 'content entry unknown type' => [
            ['content' => [['type' => 'unknown']]],
            'CallToolResult content "type" must be one of "text", "image", "audio", "resource_link", "resource", \'unknown\' given.',
        ];

        yield 'structuredContent not an object' => [
            ['content' => [], 'structuredContent' => 'oops'],
            '"result.structuredContent" must be an object, string given.',
        ];

        yield 'structuredContent list-keyed' => [
            ['content' => [], 'structuredContent' => ['x']],
            '"result.structuredContent" must be a string-keyed object.',
        ];

        yield 'isError not a bool' => [
            ['content' => [], 'isError' => 'oops'],
            '"result.isError" must be a bool or null, string given.',
        ];

        yield '_meta not an object' => [
            ['content' => [], '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];
    }
}
