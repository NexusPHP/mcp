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

namespace Nexus\Mcp\Tests\Server\Resource;

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\Resource\ReflectedResourceResult;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectedResourceResult::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedResourceResultTest extends TestCase
{
    public function testReturnsReadResourceResultUnchanged(): void
    {
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'mem://x', text: 'body')], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame($expected, self::adapt($expected));
    }

    public function testWrapsStringAsTextResourceContents(): void
    {
        $result = self::adapt('hello', 'mem://greeting');

        $contents = $result->contents[0] ?? null;

        if (! $contents instanceof TextResourceContents) {
            self::fail('Expected TextResourceContents.');
        }

        self::assertSame('mem://greeting', $contents->uri);
        self::assertSame('hello', $contents->text);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testWrapsSingleResourceContents(): void
    {
        $blob = new BlobResourceContents(uri: 'mem://b', blob: 'YmJi');

        $result = self::adapt($blob);

        self::assertSame([$blob], $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testReturnsResourceContentsList(): void
    {
        $list = [new TextResourceContents(uri: 'mem://t', text: 't'), new BlobResourceContents(uri: 'mem://b', blob: 'YmJi')];

        $result = self::adapt($list);

        self::assertSame($list, $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testThrowsOnMapOfContents(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::adapt(['main' => new TextResourceContents(uri: 'mem://t', text: 't')]);
    }

    public function testThrowsOnEmptyArray(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::adapt([]);
    }

    public function testThrowsOnNonContentsList(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::adapt([1, 2]);
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::resourceResult() must return a '.ReadResourceResult::class.', a string, or resource contents, int given.');

        self::adapt(7);
    }

    private static function adapt(mixed $result, string $uri = 'mem://resource'): ReadResourceResult
    {
        $adapted = ReflectedResourceResult::adapt($result, $uri, new \ReflectionMethod(ReflectedHandlers::class, 'resourceResult'));

        if (! $adapted instanceof ReadResourceResult) {
            self::fail('Expected a ReadResourceResult.');
        }

        return $adapted;
    }
}
