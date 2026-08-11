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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\CompositeResourceStore;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CompositeResourceStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompositeResourceStoreTest extends AbstractMcpTestCase
{
    public function testReadPrefersExactStaticUriMatch(): void
    {
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///etc', text: 'static')], ttlMs: 0, cacheScope: CacheScope::Private);
        $templateCalled = false;

        $composite = new CompositeResourceStore(
            new ResourceStore([
                'file:///etc' => new ResourceEntry(
                    new Resource(name: 'etc', uri: 'file:///etc'),
                    new ClosureResourceReader(static fn(): ReadResourceResult => $expected),
                ),
            ]),
            new ResourceTemplateStore([
                'file:///{path}' => new ResourceTemplateEntry(
                    new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                    new ClosureTemplatedResourceReader(
                        static function () use (&$templateCalled): ReadResourceResult {
                            $templateCalled = true;

                            return new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///', text: 'template')], ttlMs: 0, cacheScope: CacheScope::Private);
                        },
                    ),
                ),
            ]),
        );

        self::assertSame($expected, $composite->read('file:///etc', self::makeContext()));
        self::assertFalse($templateCalled);
    }

    public function testReadFallsThroughToTemplateOnStaticMiss(): void
    {
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///etc', text: 'matched')], ttlMs: 0, cacheScope: CacheScope::Private);

        $composite = new CompositeResourceStore(
            new ResourceStore(),
            new ResourceTemplateStore([
                'file:///{path}' => new ResourceTemplateEntry(
                    new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                    new ClosureTemplatedResourceReader(static fn(): ReadResourceResult => $expected),
                ),
            ]),
        );

        self::assertSame($expected, $composite->read('file:///etc', self::makeContext()));
    }

    public function testReadThrowsResourceNotFoundWhenNeitherMatches(): void
    {
        $composite = new CompositeResourceStore(
            new ResourceStore(),
            new ResourceTemplateStore([
                'weather://{city}' => new ResourceTemplateEntry(
                    new ResourceTemplate(name: 'weather', uriTemplate: 'weather://{city}'),
                    new ClosureTemplatedResourceReader(
                        static fn(): never => throw new \LogicException('unreachable'),
                    ),
                ),
            ]),
        );

        $this->expectException(ResourceNotFoundException::class);

        $composite->read('http://example.com/etc', self::makeContext());
    }

    public function testAReaderRaisedRefusalPropagatesRatherThanServingATemplate(): void
    {
        $templateCalled = false;

        $composite = new CompositeResourceStore(
            new ResourceStore([
                'db://users/42' => new ResourceEntry(
                    new Resource(name: 'user', uri: 'db://users/42'),
                    new ClosureResourceReader(static fn(): never => throw new ResourceNotFoundException('db://users/42')),
                ),
            ]),
            new ResourceTemplateStore([
                'db://users/{id}' => new ResourceTemplateEntry(
                    new ResourceTemplate(name: 'users', uriTemplate: 'db://users/{id}'),
                    new ClosureTemplatedResourceReader(
                        static function () use (&$templateCalled): ReadResourceResult {
                            $templateCalled = true;

                            return new ReadResourceResult(contents: [new TextResourceContents(uri: 'db://users/42', text: 'template fallback')], ttlMs: 0, cacheScope: CacheScope::Private);
                        },
                    ),
                ),
            ]),
        );

        try {
            $composite->read('db://users/42', self::makeContext());
            self::fail('A refusal the reader raised must reach the caller.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('Resource "db://users/42" not found.', $e->getMessage());
        }

        self::assertFalse($templateCalled, 'An overlapping template must not answer a read its reader refused.');
    }

    public function testListDelegatesToPrimaryUnchanged(): void
    {
        $static = new Resource(name: 'etc', uri: 'file:///etc');
        $composite = new CompositeResourceStore(
            new ResourceStore([
                'file:///etc' => new ResourceEntry(
                    $static,
                    new ClosureResourceReader(
                        static fn(): never => throw new \LogicException('unreachable'),
                    ),
                ),
            ]),
            new ResourceTemplateStore(),
        );

        $result = $composite->list(null);

        self::assertCount(1, $result->resources);
        self::assertSame($static, $result->resources[0]);
    }

    public function testAChangeInEitherInnerStoreReachesTheListener(): void
    {
        $resources = new ResourceStore();
        $templates = new ResourceTemplateStore();
        $composite = new CompositeResourceStore($resources, $templates);

        $heard = 0;
        $composite->onListChanged(static function () use (&$heard): void {
            ++$heard;
        });

        $resources->addResource(
            new Resource(name: 'etc', uri: 'file:///etc'),
            new ClosureResourceReader(static fn(): never => throw new \LogicException('unreachable')),
        );
        $templates->addResourceTemplate(
            new ResourceTemplate(name: 'logs', uriTemplate: 'file:///logs/{name}'),
            new ClosureTemplatedResourceReader(static fn(): never => throw new \LogicException('unreachable')),
        );

        self::assertSame(2, $heard, 'A composite that hides its inner stores must still report their changes.');
    }

    public function testListAcceptsCursor(): void
    {
        $composite = new CompositeResourceStore(
            new ResourceStore(
                [
                    'file:///a' => new ResourceEntry(
                        new Resource(name: 'a', uri: 'file:///a'),
                        new ClosureResourceReader(
                            static fn(): never => throw new \LogicException('unreachable'),
                        ),
                    ),
                    'file:///b' => new ResourceEntry(
                        new Resource(name: 'b', uri: 'file:///b'),
                        new ClosureResourceReader(
                            static fn(): never => throw new \LogicException('unreachable'),
                        ),
                    ),
                ],
                pageSize: 1,
            ),
            new ResourceTemplateStore(),
        );

        $second = $composite->list(new Cursor(cursor: 'file:///a'));

        self::assertCount(1, $second->resources);
        self::assertSame('b', $second->resources[0]->name);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
