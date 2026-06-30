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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClosureTemplatedResourceReader::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ClosureTemplatedResourceReaderTest extends TestCase
{
    public function testForwardsUriBindingsAndContextToClosureAndReturnsItsResult(): void
    {
        $captured = ['uri' => null, 'bindings' => null, 'context' => null];
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///etc', text: 'ok')], ttlMs: 0, cacheScope: CacheScope::Private);
        $context = new ServerContext(
            new RequestId(id: 42),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );

        $reader = new ClosureTemplatedResourceReader(
            static function (string $uri, array $bindings, ServerContext $ctx) use (&$captured, $expected): ReadResourceResult {
                $captured['uri'] = $uri;
                $captured['bindings'] = $bindings;
                $captured['context'] = $ctx;

                return $expected;
            },
        );

        $result = $reader->read('file:///etc', ['path' => 'etc'], $context);

        self::assertSame($expected, $result);
        self::assertSame('file:///etc', $captured['uri']);
        self::assertSame(['path' => 'etc'], $captured['bindings']);
        self::assertSame($context, $captured['context']);
    }
}
