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

namespace Nexus\Mcp\Tests\Core\Schema\Resource;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TextResourceContents::class)]
#[CoversClass(ResourceContents::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TextResourceContentsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $contents = new TextResourceContents('file:///x', 'hello');

        self::assertSame('file:///x', $contents->uri);
        self::assertSame('hello', $contents->text);
        self::assertNull($contents->mimeType);
        self::assertNull($contents->meta);
    }

    public function testConstructionWithAllFields(): void
    {
        $contents = new TextResourceContents(
            'file:///x',
            'hello',
            'text/plain',
            new Meta(['vendor' => 'x']),
        );

        self::assertSame('text/plain', $contents->mimeType);
        self::assertNotNull($contents->meta);
        self::assertSame(['vendor' => 'x'], $contents->meta->extras);
    }

    public function testConstructorRejectsUriViolatingRfc3986(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResourceContents URI must be a valid RFC 3986/');

        new TextResourceContents('not-a-uri', 'hello');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceContents mimeType must be a non-empty string or null.');

        new TextResourceContents('file:///x', 'hello', '');
    }

    public function testToArrayMinimal(): void
    {
        $contents = new TextResourceContents('file:///x', 'hello');

        self::assertSame(
            ['uri' => 'file:///x', 'text' => 'hello'],
            $contents->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $contents = new TextResourceContents(
            'file:///x',
            'hello',
            'text/plain',
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'uri' => 'file:///x',
                'mimeType' => 'text/plain',
                '_meta' => ['vendor' => 'x'],
                'text' => 'hello',
            ],
            $contents->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $contents = new TextResourceContents('file:///x', 'hello', 'text/plain');

        self::assertSame($contents->toArray(), $contents->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $contents = TextResourceContents::fromArray(['uri' => 'file:///x', 'text' => 'hello']);

        self::assertSame('file:///x', $contents->uri);
        self::assertSame('hello', $contents->text);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $contents = TextResourceContents::fromArray([
            'uri' => 'file:///x',
            'text' => 'hello',
            'mimeType' => 'text/plain',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('text/plain', $contents->mimeType);
        self::assertNotNull($contents->meta);
        self::assertSame(['vendor' => 'x'], $contents->meta->extras);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new TextResourceContents(
            'file:///x',
            'hello',
            'text/plain',
            new Meta(['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), TextResourceContents::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        TextResourceContents::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing uri' => [
            ['text' => 'hello'],
            'TextResourceContents wire data missing "uri".',
        ];

        yield 'uri not a string' => [
            ['uri' => 1, 'text' => 'hello'],
            'TextResourceContents wire "uri" must be a string, int given.',
        ];

        yield 'missing text' => [
            ['uri' => 'file:///x'],
            'TextResourceContents wire data missing "text".',
        ];

        yield 'text not a string' => [
            ['uri' => 'file:///x', 'text' => 1],
            'TextResourceContents wire "text" must be a string, int given.',
        ];

        yield 'mimeType not a string' => [
            ['uri' => 'file:///x', 'text' => 'hello', 'mimeType' => 1],
            'TextResourceContents wire "mimeType" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', 'text' => 'hello', '_meta' => 'oops'],
            'ResourceContents "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', 'text' => 'hello', '_meta' => ['x']],
            'ResourceContents "_meta" must be a string-keyed object.',
        ];
    }
}
