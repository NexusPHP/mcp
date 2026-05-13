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

namespace Nexus\Mcp\Tests\Core\Schema\ContentBlock;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TextContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TextContentTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $content = new TextContent('hello');

        self::assertSame('hello', $content->text);
        self::assertNull($content->annotations);
        self::assertSame([], $content->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $content = new TextContent('hello');

        self::assertSame(
            ['text' => 'hello', 'type' => 'text'],
            $content->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $content = new TextContent(
            'hello',
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'text' => 'hello',
                'type' => 'text',
                'annotations' => ['priority' => 0.5],
                '_meta' => ['vendor' => 'x'],
            ],
            $content->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $content = new TextContent('hello', null, new MetaObject(['k' => 'v']));

        self::assertSame($content->toArray(), $content->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $content = TextContent::fromArray([
            'type' => 'text',
            'text' => 'hello',
            'annotations' => ['priority' => 0.5],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('hello', $content->text);
        self::assertNotNull($content->annotations);
        self::assertSame(0.5, $content->annotations->priority);
        self::assertSame(['vendor' => 'x'], $content->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TextContent(
            'hello',
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = TextContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        TextContent::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['text' => 'hello'],
            'TextContent data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'image', 'text' => 'hello'],
            'TextContent "type" must be "text", \'image\' given.',
        ];

        yield 'missing text' => [
            ['type' => 'text'],
            'TextContent data missing "text".',
        ];

        yield 'text not a string' => [
            ['type' => 'text', 'text' => 1],
            'TextContent "text" must be a string, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'text', 'text' => 'hello', 'annotations' => 'oops'],
            'TextContent "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'text', 'text' => 'hello', 'annotations' => ['x']],
            'TextContent "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'text', 'text' => 'hello', '_meta' => 'oops'],
            'TextContent "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'text', 'text' => 'hello', '_meta' => ['x']],
            'TextContent "_meta" must be a string-keyed object.',
        ];
    }
}
