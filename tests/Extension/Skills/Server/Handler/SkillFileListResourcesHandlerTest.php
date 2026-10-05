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

namespace Nexus\Mcp\Tests\Extension\Skills\Server\Handler;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileListResourcesHandler;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillFileListResourcesHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillFileListResourcesHandlerTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';
    private const string GIT = 'skill://git-workflow';
    private const string PREFIX = 'io.modelcontextprotocol/skills:';

    public function testTheFirstSkillFilesFollowTheLastOfTheOtherResources(): void
    {
        $inner = new ListResourcesResult(
            [new Resource('notes', 'file:///notes.txt'), new Resource('todo', 'file:///todo.txt')],
            0,
            CacheScope::Private,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );
        $handler = new SkillFileListResourcesHandler($this->answerWith($inner), $this->buildStore(pageSize: 2));

        $result = $handler->handle($this->buildRequest(), $this->buildContext());

        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertSame(
            ['file:///notes.txt', 'file:///todo.txt', self::GIT.'/SKILL.md', self::GIT.'/references/GUIDE.md'],
            self::listUris($result),
        );
        self::assertSame(self::PREFIX.self::GIT.'/references/GUIDE.md', $result->nextCursor?->cursor);
        self::assertSame(['vendor' => 'x'], $result->meta->toArray());
    }

    public function testASkillCursorContinuesThroughTheSkillFilesWithoutReachingTheInnerHandler(): void
    {
        $handler = new SkillFileListResourcesHandler(
            new ClosureRequestHandler(static fn(): Result => self::fail('The inner handler must not run for a skill cursor.')),
            $this->buildStore(pageSize: 1, ttlMs: 60_000, cacheScope: CacheScope::Public),
        );
        $context = $this->buildContext();

        $second = $handler->handle($this->buildRequest(self::PREFIX.self::GIT.'/SKILL.md'), $context);
        $last = $handler->handle($this->buildRequest(self::PREFIX.self::GIT.'/scripts/sync.sh'), $context);

        self::assertInstanceOf(ListResourcesResult::class, $second);
        self::assertSame([self::GIT.'/references/GUIDE.md'], self::listUris($second));
        self::assertSame(self::PREFIX.self::GIT.'/references/GUIDE.md', $second->nextCursor?->cursor);
        self::assertSame(60_000, $second->ttlMs);
        self::assertSame(CacheScope::Public, $second->cacheScope);
        self::assertInstanceOf(ListResourcesResult::class, $last);
        self::assertSame([self::GIT.'/templates/commit/message.md'], self::listUris($last));
        self::assertNull($last->nextCursor);
    }

    public function testAServerHoldingNoSkillFileListsItsOtherResourcesAlone(): void
    {
        $inner = new ListResourcesResult([new Resource('notes', 'file:///notes.txt')], 300, CacheScope::Public);
        $handler = new SkillFileListResourcesHandler($this->answerWith($inner), new SkillStore(ttlMs: 300, cacheScope: CacheScope::Public));

        $result = $handler->handle($this->buildRequest(), $this->buildContext());

        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertSame($inner->toArray(), $result->toArray());
    }

    public function testAnEarlierPageOfTheOtherResourcesIsReturnedUntouched(): void
    {
        $request = $this->buildRequest('file:///a.txt');
        $context = $this->buildContext();
        $inner = new ListResourcesResult([new Resource('notes', 'file:///notes.txt')], 0, CacheScope::Private, new Cursor('file:///notes.txt'));
        $handler = new SkillFileListResourcesHandler(
            new ClosureRequestHandler(static function (JsonRpcRequest $seen, AbstractContext $seenContext) use ($request, $context, $inner): Result {
                self::assertSame($request, $seen);
                self::assertSame($context, $seenContext);

                return $inner;
            }),
            $this->buildStore(),
        );

        self::assertSame($inner, $handler->handle($request, $context));
    }

    public function testAResultThatIsNotAResourceListingIsReturnedUntouched(): void
    {
        $inner = new EmptyResult();
        $handler = new SkillFileListResourcesHandler($this->answerWith($inner), $this->buildStore());

        self::assertSame($inner, $handler->handle($this->buildRequest(), $this->buildContext()));
    }

    /**
     * @param int<0, max> $innerTtlMs
     * @param int<0, max> $skillTtlMs
     */
    #[DataProvider('provideTheJoinedPageTakesTheStricterCachePolicyCases')]
    public function testTheJoinedPageTakesTheStricterCachePolicy(
        int $innerTtlMs,
        CacheScope $innerScope,
        int $skillTtlMs,
        CacheScope $skillScope,
        int $expectedTtlMs,
        CacheScope $expectedScope,
    ): void {
        $handler = new SkillFileListResourcesHandler(
            $this->answerWith(new ListResourcesResult([], $innerTtlMs, $innerScope)),
            $this->buildStore(ttlMs: $skillTtlMs, cacheScope: $skillScope),
        );

        $result = $handler->handle($this->buildRequest(), $this->buildContext());

        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertSame($expectedTtlMs, $result->ttlMs);
        self::assertSame($expectedScope, $result->cacheScope);
    }

    /**
     * @return iterable<string, array{int<0, max>, CacheScope, int<0, max>, CacheScope, int, CacheScope}>
     */
    public static function provideTheJoinedPageTakesTheStricterCachePolicyCases(): iterable
    {
        yield 'the skill files expire sooner' => [300, CacheScope::Public, 60, CacheScope::Public, 60, CacheScope::Public];

        yield 'the other resources expire sooner' => [60, CacheScope::Public, 300, CacheScope::Public, 60, CacheScope::Public];

        yield 'only the other resources are private' => [0, CacheScope::Private, 0, CacheScope::Public, 0, CacheScope::Private];

        yield 'only the skill files are private' => [0, CacheScope::Public, 0, CacheScope::Private, 0, CacheScope::Private];

        yield 'both are private' => [0, CacheScope::Private, 0, CacheScope::Private, 0, CacheScope::Private];
    }

    public function testASkillCursorNamingNoEntryIsRefused(): void
    {
        $handler = new SkillFileListResourcesHandler($this->answerWith(new EmptyResult()), $this->buildStore());

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageIs('Cursor "nope" does not match any registered entry.');

        $handler->handle($this->buildRequest(self::PREFIX.'nope'), $this->buildContext());
    }

    public function testTheBareSkillCursorPrefixIsRefused(): void
    {
        $handler = new SkillFileListResourcesHandler($this->answerWith(new EmptyResult()), $this->buildStore());

        try {
            $handler->handle($this->buildRequest(self::PREFIX), $this->buildContext());
            self::fail('The cursor was not refused.');
        } catch (InvalidCursorException $e) {
            self::assertSame('Cursor "io.modelcontextprotocol/skills:" does not match any registered entry.', $e->getMessage());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    /**
     * @param int<1, max> $pageSize
     * @param int<0, max> $ttlMs
     */
    private function buildStore(int $pageSize = 50, int $ttlMs = 0, CacheScope $cacheScope = CacheScope::Private): SkillStore
    {
        return new SkillStore(
            [new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow')],
            pageSize: $pageSize,
            ttlMs: $ttlMs,
            cacheScope: $cacheScope,
        );
    }

    private function answerWith(Result $result): ClosureRequestHandler
    {
        return new ClosureRequestHandler(static fn(): Result => $result);
    }

    /**
     * @return list<string>
     */
    private static function listUris(ListResourcesResult $result): array
    {
        return array_map(static fn(Resource $resource): string => $resource->uri, $result->resources);
    }

    private function buildRequest(?string $cursor = null): ListResourcesRequest
    {
        return ListResourcesRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape()] + (null === $cursor ? [] : ['cursor' => $cursor]),
        ]);
    }

    private function buildContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
