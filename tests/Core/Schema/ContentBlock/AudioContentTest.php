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
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AudioContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AudioContentTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $content = new AudioContent('aGVsbG8=', 'audio/mp3');

        self::assertSame('aGVsbG8=', $content->data);
        self::assertSame('audio/mp3', $content->mimeType);
        self::assertNull($content->annotations);
        self::assertNull($content->meta);
    }

    public function testToArrayMinimal(): void
    {
        $content = new AudioContent('aGVsbG8=', 'audio/mp3');

        self::assertSame(
            ['data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3', 'type' => 'audio'],
            $content->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $content = new AudioContent(
            'aGVsbG8=',
            'audio/mp3',
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'data' => 'aGVsbG8=',
                'mimeType' => 'audio/mp3',
                'type' => 'audio',
                'annotations' => ['priority' => 0.5],
                '_meta' => ['vendor' => 'x'],
            ],
            $content->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $content = new AudioContent('aGVsbG8=', 'audio/mp3', null, new MetaObject(['k' => 'v']));

        self::assertSame($content->toArray(), $content->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $content = AudioContent::fromArray([
            'type' => 'audio',
            'data' => 'aGVsbG8=',
            'mimeType' => 'audio/mp3',
            'annotations' => ['priority' => 0.5],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('aGVsbG8=', $content->data);
        self::assertSame('audio/mp3', $content->mimeType);
        self::assertNotNull($content->annotations);
        self::assertSame(0.5, $content->annotations->priority);
        self::assertNotNull($content->meta);
        self::assertSame(['vendor' => 'x'], $content->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new AudioContent(
            'aGVsbG8=',
            'audio/mp3',
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = AudioContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyData(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('AudioContent data must be a non-empty string.');

        new AudioContent('', 'audio/mp3');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('AudioContent mimeType must be a non-empty string.');

        new AudioContent('aGVsbG8=', '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        AudioContent::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'],
            'AudioContent wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'],
            'AudioContent wire "type" must be "audio", \'image\' given.',
        ];

        yield 'missing data' => [
            ['type' => 'audio', 'mimeType' => 'audio/mp3'],
            'AudioContent wire data missing "data".',
        ];

        yield 'data not a string' => [
            ['type' => 'audio', 'data' => 1, 'mimeType' => 'audio/mp3'],
            'AudioContent wire "data" must be a string, int given.',
        ];

        yield 'missing mimeType' => [
            ['type' => 'audio', 'data' => 'aGVsbG8='],
            'AudioContent wire data missing "mimeType".',
        ];

        yield 'mimeType not a string' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 1],
            'AudioContent wire "mimeType" must be a string, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3', 'annotations' => 'oops'],
            'AudioContent wire "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3', 'annotations' => ['x']],
            'AudioContent wire "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3', '_meta' => 'oops'],
            'AudioContent "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3', '_meta' => ['x']],
            'AudioContent "_meta" must be a string-keyed object.',
        ];
    }
}
