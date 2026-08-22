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

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TextContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TextContentTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $content = new TextContent(text: 'hello');

        self::assertSame('hello', $content->text);
        self::assertSame([], $content->annotations->toArray());
        self::assertSame([], $content->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $content = new TextContent(text: 'hello');

        self::assertSame(
            ['text' => 'hello', 'type' => 'text'],
            $content->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $content = new TextContent(
            text: 'hello',
            annotations: new Annotations(priority: 0.5),
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
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
        $content = new TextContent(text: 'hello', meta: new PayloadMetaObject(extras: ['k' => 'v']));

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
        self::assertSame(0.5, $content->annotations->priority);
        self::assertSame(['vendor' => 'x'], $content->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TextContent(
            text: 'hello',
            annotations: new Annotations(priority: 0.5),
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        TextContent::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['text' => 'hello'],
            'text content is missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'image', 'text' => 'hello'],
            'text content "type" must be \'text\', \'image\' given.',
        ];

        yield 'missing text' => [
            ['type' => 'text'],
            'text content is missing the required "text" key.',
        ];

        yield 'text not a string' => [
            ['type' => 'text', 'text' => 1],
            'text content "text" must be a string, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'text', 'text' => 'hello', 'annotations' => 'oops'],
            'text content "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'text', 'text' => 'hello', 'annotations' => ['x']],
            'text content "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'text', 'text' => 'hello', '_meta' => 'oops'],
            'text content "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'text', 'text' => 'hello', '_meta' => ['x']],
            'text content "_meta" must be a string-keyed object.',
        ];
    }
}
