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
use Nexus\Mcp\Server\Resource\ReflectedTemplatedResourceReader;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectedTemplatedResourceReader::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedTemplatedResourceReaderTest extends TestCase
{
    public function testReturnsReadResourceResultUnchanged(): void
    {
        $result = self::read('templatedResult', 'mem://users/42', ['id' => '42']);

        self::assertSame('profile', self::firstText($result));
    }

    public function testWrapsStringAndBindsTemplateVariable(): void
    {
        $result = self::read('templatedBinding', 'mem://users/42', ['id' => '42']);

        $contents = $result->contents[0] ?? null;

        if (! $contents instanceof TextResourceContents) {
            self::fail('Expected TextResourceContents.');
        }

        self::assertSame('mem://users/42', $contents->uri);
        self::assertSame('user 42 for session-1', $contents->text);
    }

    public function testBindsBothUriAndTemplateVariable(): void
    {
        $result = self::read('templatedUri', 'mem://users/42', ['id' => '42']);

        self::assertSame('mem://users/42#42', self::firstText($result));
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::resourceUnsupported() must return a '.ReadResourceResult::class.', a string, or resource contents, bool given.');

        self::read('resourceUnsupported', 'mem://x', []);
    }

    /**
     * @param array<string, string> $bindings
     */
    private static function read(string $method, string $uri, array $bindings): ReadResourceResult
    {
        $reader = new ReflectedTemplatedResourceReader(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        return $reader->read($uri, $bindings, self::makeContext());
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
