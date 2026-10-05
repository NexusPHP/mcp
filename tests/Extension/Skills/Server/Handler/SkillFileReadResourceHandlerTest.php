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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileReadResourceHandler;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Extension\Skills\ArraySkillProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillFileReadResourceHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillFileReadResourceHandlerTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';

    public function testASkillFileIsServedWithoutReachingTheInnerHandler(): void
    {
        $handler = new SkillFileReadResourceHandler(
            new ClosureRequestHandler(static fn(): Result => self::fail('The inner handler must not run for a skill file.')),
            new SkillStore([new DirectorySkill('refunds', self::FIXTURES.'/refunds')]),
        );

        $result = $handler->handle($this->buildRequest('skill://refunds/SKILL.md'), $this->buildContext());

        self::assertInstanceOf(ReadResourceResult::class, $result);
        self::assertArrayHasKey(0, $result->contents);
        self::assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        self::assertSame(file_get_contents(self::FIXTURES.'/refunds/SKILL.md'), $result->contents[0]->text);
    }

    public function testTheContextReachesTheProvider(): void
    {
        $file = new SkillFile('skill://reports/q3/notes.md', 'notes', 'text/markdown');
        $provider = new ArraySkillProvider(files: [$file->uri => $file]);
        $handler = new SkillFileReadResourceHandler(
            new ClosureRequestHandler(static fn(): Result => self::fail('The inner handler must not run for a skill file.')),
            new SkillStore(provider: $provider),
        );

        $result = $handler->handle($this->buildRequest($file->uri), $this->buildContext());

        self::assertInstanceOf(ReadResourceResult::class, $result);
        self::assertSame([['findFile', $file->uri]], $provider->lookups);
    }

    public function testAnyOtherUriReachesTheInnerHandlerUnchanged(): void
    {
        $request = $this->buildRequest('file:///notes.txt');
        $context = $this->buildContext();
        $inner = new ReadResourceResult([new TextResourceContents('file:///notes.txt', 'inner')], 0, CacheScope::Private);
        $handler = new SkillFileReadResourceHandler(
            new ClosureRequestHandler(static function (JsonRpcRequest $seen, AbstractContext $seenContext) use ($request, $context, $inner): Result {
                self::assertSame($request, $seen);
                self::assertSame($context, $seenContext);

                return $inner;
            }),
            new SkillStore([new DirectorySkill('refunds', self::FIXTURES.'/refunds')]),
        );

        self::assertSame($inner, $handler->handle($request, $context));
    }

    /**
     * @param non-empty-string $uri
     */
    private function buildRequest(string $uri): ReadResourceRequest
    {
        return ReadResourceRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'uri' => $uri],
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
