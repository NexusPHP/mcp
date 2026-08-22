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
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\Resource\ReflectedTemplatedResourceReader;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReflectedTemplatedResourceReader::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedTemplatedResourceReaderTest extends AbstractMcpTestCase
{
    public function testReturnsReadResourceResultUnchanged(): void
    {
        $result = $this->read('templatedResult', 'mem://users/42', ['id' => '42']);

        self::assertSame('profile', $this->readFirstText($result));
    }

    public function testWrapsStringAndBindsTemplateVariable(): void
    {
        $result = $this->read('templatedBinding', 'mem://users/42', ['id' => '42']);

        $contents = $result->contents[0] ?? null;

        self::assertInstanceOf(TextResourceContents::class, $contents);

        self::assertSame('mem://users/42', $contents->uri);
        self::assertSame('user 42 for test-client', $contents->text);
    }

    public function testBindsBothUriAndTemplateVariable(): void
    {
        $result = $this->read('templatedUri', 'mem://users/42', ['id' => '42']);

        self::assertSame('mem://users/42#42', $this->readFirstText($result));
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::resourceUnsupported() must return a '.ReadResourceResult::class.', a string, or resource contents, bool given.');

        $this->read('resourceUnsupported', 'mem://x', []);
    }

    /**
     * @param non-empty-string      $uri
     * @param array<string, string> $bindings
     */
    private function read(string $method, string $uri, array $bindings): ReadResourceResult
    {
        $reader = new ReflectedTemplatedResourceReader(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        $result = $reader->read($uri, $bindings, $this->makeContext());

        self::assertInstanceOf(ReadResourceResult::class, $result);

        return $result;
    }

    private function readFirstText(ReadResourceResult $result): string
    {
        $contents = $result->contents[0] ?? null;

        self::assertInstanceOf(TextResourceContents::class, $contents);

        return $contents->text;
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
