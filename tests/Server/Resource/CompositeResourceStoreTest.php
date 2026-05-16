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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\CompositeResourceStore;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CompositeResourceStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompositeResourceStoreTest extends TestCase
{
    public function testReadPrefersExactStaticUriMatch(): void
    {
        $expected = new ReadResourceResult([new TextResourceContents('file:///etc', 'static')]);
        $templateCalled = false;

        $composite = new CompositeResourceStore(
            new ResourceStore([
                'file:///etc' => [
                    'resource' => new Resource('etc', 'file:///etc'),
                    'reader' => new ClosureResourceReader(
                        static fn(): ReadResourceResult => $expected,
                    ),
                ],
            ]),
            new ResourceTemplateStore([
                'file:///{path}' => [
                    'template' => new ResourceTemplate('files', 'file:///{path}'),
                    'reader' => new ClosureTemplatedResourceReader(
                        static function () use (&$templateCalled): ReadResourceResult {
                            $templateCalled = true;

                            return new ReadResourceResult([new TextResourceContents('file:///', 'template')]);
                        },
                    ),
                ],
            ]),
        );

        self::assertSame($expected, $composite->read('file:///etc', self::makeContext()));
        self::assertFalse($templateCalled);
    }

    public function testReadFallsThroughToTemplateOnStaticMiss(): void
    {
        $expected = new ReadResourceResult([new TextResourceContents('file:///etc', 'matched')]);

        $composite = new CompositeResourceStore(
            new ResourceStore(),
            new ResourceTemplateStore([
                'file:///{path}' => [
                    'template' => new ResourceTemplate('files', 'file:///{path}'),
                    'reader' => new ClosureTemplatedResourceReader(
                        static fn(): ReadResourceResult => $expected,
                    ),
                ],
            ]),
        );

        self::assertSame($expected, $composite->read('file:///etc', self::makeContext()));
    }

    public function testReadThrowsResourceNotFoundWhenNeitherMatches(): void
    {
        $composite = new CompositeResourceStore(
            new ResourceStore(),
            new ResourceTemplateStore([
                'weather://{city}' => [
                    'template' => new ResourceTemplate('weather', 'weather://{city}'),
                    'reader' => new ClosureTemplatedResourceReader(
                        static fn(): never => throw new \LogicException('unreachable'),
                    ),
                ],
            ]),
        );

        $this->expectException(ResourceNotFoundException::class);

        $composite->read('http://example.com/etc', self::makeContext());
    }

    public function testListDelegatesToPrimaryUnchanged(): void
    {
        $static = new Resource('etc', 'file:///etc');
        $composite = new CompositeResourceStore(
            new ResourceStore([
                'file:///etc' => [
                    'resource' => $static,
                    'reader' => new ClosureResourceReader(
                        static fn(): never => throw new \LogicException('unreachable'),
                    ),
                ],
            ]),
            new ResourceTemplateStore(),
        );

        $result = $composite->list(null);

        self::assertCount(1, $result->resources);
        self::assertSame($static, $result->resources[0]);
    }

    public function testListAcceptsCursor(): void
    {
        $composite = new CompositeResourceStore(
            new ResourceStore(
                [
                    'file:///a' => [
                        'resource' => new Resource('a', 'file:///a'),
                        'reader' => new ClosureResourceReader(
                            static fn(): never => throw new \LogicException('unreachable'),
                        ),
                    ],
                    'file:///b' => [
                        'resource' => new Resource('b', 'file:///b'),
                        'reader' => new ClosureResourceReader(
                            static fn(): never => throw new \LogicException('unreachable'),
                        ),
                    ],
                ],
                pageSize: 1,
            ),
            new ResourceTemplateStore(),
        );

        $second = $composite->list(new Cursor('file:///a'));

        self::assertCount(1, $second->resources);
        self::assertSame('b', $second->resources[0]->name);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
