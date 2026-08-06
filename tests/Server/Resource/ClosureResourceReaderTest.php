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
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClosureResourceReader::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ClosureResourceReaderTest extends AbstractMcpTestCase
{
    public function testForwardsUriAndContextToClosure(): void
    {
        $captured = ['uri' => '', 'requestId' => 0];
        $expected = new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private);
        $reader = new ClosureResourceReader(
            static function (string $uri, ServerContext $context) use ($expected, &$captured): ReadResourceResult {
                $captured = ['uri' => $uri, 'requestId' => $context->requestId->id];

                return $expected;
            },
        );

        $result = $reader->read('file:///a', self::makeContext());

        self::assertSame($expected, $result);
        self::assertSame(['uri' => 'file:///a', 'requestId' => 7], $captured);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
