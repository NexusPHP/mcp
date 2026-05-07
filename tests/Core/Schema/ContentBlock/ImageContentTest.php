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
use Nexus\Mcp\Core\Schema\Meta;
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
        $content = new ImageContent('aGVsbG8=', 'image/png');

        self::assertSame('aGVsbG8=', $content->data);
        self::assertSame('image/png', $content->mimeType);
        self::assertNull($content->annotations);
        self::assertNull($content->meta);
    }

    public function testToArrayMinimal(): void
    {
        $content = new ImageContent('aGVsbG8=', 'image/png');

        self::assertSame(
            ['data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'type' => 'image'],
            $content->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $content = new ImageContent(
            'aGVsbG8=',
            'image/png',
            new Annotations(null, 0.5),
            new Meta(['vendor' => 'x']),
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
        $content = new ImageContent('aGVsbG8=', 'image/png', null, new Meta(['k' => 'v']));

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
        self::assertNotNull($content->annotations);
        self::assertSame(0.5, $content->annotations->priority);
        self::assertNotNull($content->meta);
        self::assertSame(['vendor' => 'x'], $content->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ImageContent(
            'aGVsbG8=',
            'image/png',
            new Annotations(null, 0.5),
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = ImageContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyData(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ImageContent data must be a non-empty string.');

        new ImageContent('', 'image/png');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ImageContent mimeType must be a non-empty string.');

        new ImageContent('aGVsbG8=', '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ImageContent::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['data' => 'aGVsbG8=', 'mimeType' => 'image/png'],
            'ImageContent wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'],
            'ImageContent wire "type" must be "image", \'audio\' given.',
        ];

        yield 'missing data' => [
            ['type' => 'image', 'mimeType' => 'image/png'],
            'ImageContent wire data missing "data".',
        ];

        yield 'data not a string' => [
            ['type' => 'image', 'data' => 1, 'mimeType' => 'image/png'],
            'ImageContent wire "data" must be a string, int given.',
        ];

        yield 'missing mimeType' => [
            ['type' => 'image', 'data' => 'aGVsbG8='],
            'ImageContent wire data missing "mimeType".',
        ];

        yield 'mimeType not a string' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 1],
            'ImageContent wire "mimeType" must be a string, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'annotations' => 'oops'],
            'ImageContent wire "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'annotations' => ['x']],
            'ImageContent wire "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', '_meta' => 'oops'],
            'ImageContent "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', '_meta' => ['x']],
            'ImageContent "_meta" must be a string-keyed object.',
        ];
    }
}
