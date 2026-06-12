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
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ImageContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ImageContentTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $content = new ImageContent(data: 'aGVsbG8=', mimeType: 'image/png');

        self::assertSame('aGVsbG8=', $content->data);
        self::assertSame('image/png', $content->mimeType);
        self::assertSame([], $content->annotations->toArray());
        self::assertSame([], $content->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $content = new ImageContent(data: 'aGVsbG8=', mimeType: 'image/png');

        self::assertSame(
            ['data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'type' => 'image'],
            $content->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $content = new ImageContent(
            data: 'aGVsbG8=',
            mimeType: 'image/png',
            annotations: new Annotations(priority: 0.5),
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                'data' => 'aGVsbG8=',
                'mimeType' => 'image/png',
                'type' => 'image',
                'annotations' => ['priority' => 0.5],
                '_meta' => ['vendor' => 'x'],
            ],
            $content->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $content = new ImageContent(data: 'aGVsbG8=', mimeType: 'image/png', meta: new MetaObject(extras: ['k' => 'v']));

        self::assertSame($content->toArray(), $content->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $content = ImageContent::fromArray([
            'type' => 'image',
            'data' => 'aGVsbG8=',
            'mimeType' => 'image/png',
            'annotations' => ['priority' => 0.5],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('aGVsbG8=', $content->data);
        self::assertSame('image/png', $content->mimeType);
        self::assertSame(0.5, $content->annotations->priority);
        self::assertSame(['vendor' => 'x'], $content->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ImageContent(
            data: 'aGVsbG8=',
            mimeType: 'image/png',
            annotations: new Annotations(priority: 0.5),
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ImageContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyData(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('image content "data" must be a non-empty string.');

        new ImageContent(data: '', mimeType: 'image/png');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('image content "mimeType" must be a non-empty string.');

        new ImageContent(data: 'aGVsbG8=', mimeType: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ImageContent::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['data' => 'aGVsbG8=', 'mimeType' => 'image/png'],
            'image content missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'],
            'image content "type" must be \'image\', \'audio\' given.',
        ];

        yield 'missing data' => [
            ['type' => 'image', 'mimeType' => 'image/png'],
            'image content missing the required "data" key.',
        ];

        yield 'data not a string' => [
            ['type' => 'image', 'data' => 1, 'mimeType' => 'image/png'],
            'image content "data" must be a string, int given.',
        ];

        yield 'missing mimeType' => [
            ['type' => 'image', 'data' => 'aGVsbG8='],
            'image content missing the required "mimeType" key.',
        ];

        yield 'mimeType not a string' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 1],
            'image content "mimeType" must be a string, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'annotations' => 'oops'],
            'image content "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'annotations' => ['x']],
            'image content "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', '_meta' => 'oops'],
            'image content "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', '_meta' => ['x']],
            'image content "_meta" must be a string-keyed object.',
        ];
    }
}
