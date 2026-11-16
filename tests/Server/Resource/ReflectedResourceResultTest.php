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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReflectedResourceResult::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedResourceResultTest extends AbstractMcpTestCase
{
    public function testReturnsReadResourceResultUnchanged(): void
    {
        $expected = new ReadResourceResult(contents: [
            new TextResourceContents(uri: 'mem://x', text: 'body'),
        ], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame($expected, $this->adapt($expected));
    }

    public function testWrapsStringAsTextResourceContents(): void
    {
        $result = $this->adapt('hello', 'mem://greeting');

        $contents = $result->contents[0] ?? null;

        self::assertInstanceOf(TextResourceContents::class, $contents);

        self::assertSame('mem://greeting', $contents->uri);
        self::assertSame('hello', $contents->text);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testWrapsAStringThatIsNotUtf8AsBlobResourceContents(): void
    {
        $contents = $this->adapt("\x89PNG\r\n\x1a\n\xff", 'mem://image', 'image/png')->contents[0] ?? null;

        self::assertInstanceOf(BlobResourceContents::class, $contents);
        self::assertSame('mem://image', $contents->uri);
        self::assertSame(base64_encode("\x89PNG\r\n\x1a\n\xff"), $contents->blob);
        self::assertSame('image/png', $contents->mimeType);
    }

    public function testLabelsWrappedTextWithTheDeclaredMimeType(): void
    {
        $contents = $this->adapt('# Title', 'mem://readme', 'text/markdown')->contents[0] ?? null;

        self::assertInstanceOf(TextResourceContents::class, $contents);
        self::assertSame('text/markdown', $contents->mimeType);
    }

    public function testWrapsSingleResourceContents(): void
    {
        $blob = new BlobResourceContents(uri: 'mem://b', blob: 'YmJi');

        $result = $this->adapt($blob);

        self::assertSame([$blob], $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testReturnsResourceContentsList(): void
    {
        $list = [
            new TextResourceContents(uri: 'mem://t', text: 't'),
            new BlobResourceContents(uri: 'mem://b', blob: 'YmJi'),
        ];

        $result = $this->adapt($list);

        self::assertSame($list, $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testReadsContentsUnderIntegerKeys(): void
    {
        $contents = new TextResourceContents(uri: 'mem://t', text: 't');

        self::assertSame([$contents], $this->adapt([4 => $contents])->contents);
    }

    public function testThrowsOnMapOfContents(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->adapt(['main' => new TextResourceContents(uri: 'mem://t', text: 't')]);
    }

    public function testThrowsOnEmptyArray(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->adapt([]);
    }

    public function testThrowsOnNonContentsList(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->adapt([1, 2]);
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::resourceResult() must return a '.ReadResourceResult::class.', a string, or resource contents, int given.');

        $this->adapt(7);
    }

    /**
     * @param non-empty-string      $uri
     * @param null|non-empty-string $mimeType
     */
    private function adapt(mixed $result, string $uri = 'mem://resource', ?string $mimeType = null): ReadResourceResult
    {
        $adapted = ReflectedResourceResult::adapt(
            $result,
            $uri,
            new \ReflectionMethod(ReflectedHandlers::class, 'resourceResult'),
            $mimeType,
        );

        self::assertInstanceOf(ReadResourceResult::class, $adapted);

        return $adapted;
    }
}
