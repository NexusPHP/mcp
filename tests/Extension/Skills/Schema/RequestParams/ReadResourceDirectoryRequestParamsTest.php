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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\RequestParams;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Extension\Skills\Schema\RequestParams\ReadResourceDirectoryRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReadResourceDirectoryRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ReadResourceDirectoryRequestParamsTest extends AbstractMcpTestCase
{
    private const string URI = 'skill://git-workflow/references';

    public function testConstruction(): void
    {
        $params = new ReadResourceDirectoryRequestParams(uri: self::URI, meta: RequestMetaObjectFactory::create());

        self::assertSame(self::URI, $params->uri);
        self::assertNull($params->cursor);
    }

    public function testAnEmptyUriIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params.uri" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ReadResourceDirectoryRequestParams(uri: '', meta: RequestMetaObjectFactory::create());
    }

    public function testToArrayOmitsAnAbsentCursor(): void
    {
        $params = new ReadResourceDirectoryRequestParams(uri: self::URI, meta: RequestMetaObjectFactory::create());

        self::assertSame(['_meta' => RequestMetaObjectFactory::shape(), 'uri' => self::URI], $params->toArray());
        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testToArrayCarriesTheCursor(): void
    {
        $params = new ReadResourceDirectoryRequestParams(uri: self::URI, meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'next'));

        self::assertSame(['_meta' => RequestMetaObjectFactory::shape(), 'uri' => self::URI, 'cursor' => 'next'], $params->toArray());
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new ReadResourceDirectoryRequestParams(uri: self::URI, meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'next'));
        $rebuilt = ReadResourceDirectoryRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertSame('next', $rebuilt->cursor?->cursor);
    }

    public function testFromArrayReadsAnAbsentCursorAsNone(): void
    {
        self::assertNull(ReadResourceDirectoryRequestParams::fromArray(['uri' => self::URI, '_meta' => RequestMetaObjectFactory::shape()])->cursor);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ReadResourceDirectoryRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [[], '"params" is missing the required "uri" key.'];

        yield 'uri not a string' => [['uri' => 1], '"params.uri" must be a non-empty string, int given.'];

        yield 'uri empty' => [['uri' => ''], '"params.uri" must be a non-empty string, string given.'];

        yield 'cursor not a string' => [['uri' => self::URI, 'cursor' => 3], '"params.cursor" must be a non-empty string, int given.'];

        yield 'cursor null' => [['uri' => self::URI, 'cursor' => null], '"params.cursor" must be a non-empty string, null given.'];

        yield 'missing _meta' => [['uri' => self::URI], '"params" is missing the required "_meta" key.'];

        yield '_meta not an object' => [['uri' => self::URI, '_meta' => 'oops'], '"params._meta" must be an object, string given.'];

        yield '_meta list-keyed' => [['uri' => self::URI, '_meta' => ['x']], '"params._meta" must be a string-keyed object.'];
    }
}
