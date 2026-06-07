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

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\Resource\ReflectedResourceReader;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectedResourceReader::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedResourceReaderTest extends TestCase
{
    public function testReturnsReadResourceResultUnchanged(): void
    {
        $result = self::read('resourceResult', 'mem://anything');

        self::assertSame('body', self::firstText($result));
    }

    public function testWrapsStringAsTextResourceContentsBoundToUri(): void
    {
        $result = self::read('resourceUri', 'mem://fixed');

        $contents = $result->contents[0] ?? null;

        if (! $contents instanceof TextResourceContents) {
            self::fail('Expected TextResourceContents.');
        }

        self::assertSame('mem://fixed', $contents->uri);
        self::assertSame('contents of mem://fixed', $contents->text);
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::resourceUnsupported() must return a '.ReadResourceResult::class.', a string, or resource contents, bool given.');

        self::read('resourceUnsupported', 'mem://x');
    }

    private static function read(string $method, string $uri): ReadResourceResult
    {
        $reader = new ReflectedResourceReader(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        return $reader->read($uri, self::makeContext());
    }

    private static function firstText(ReadResourceResult $result): string
    {
        $contents = $result->contents[0] ?? null;

        if (! $contents instanceof TextResourceContents) {
            self::fail('Expected TextResourceContents.');
        }

        return $contents->text;
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            new RequestMetaObject(),
            'session-1',
            new RecordingSender(),
        );
    }
}
