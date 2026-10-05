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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\ListSkillsRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListSkillsRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ListSkillsRequestHandlerTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';

    public function testThePageTheCursorNamesIsServed(): void
    {
        $git = new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow');
        $refunds = new DirectorySkill('refunds', self::FIXTURES.'/refunds');
        $handler = new ListSkillsRequestHandler(new SkillStore([$git, $refunds], pageSize: 1));

        $first = $handler->handle($this->buildRequest(), $this->buildContext());
        $second = $handler->handle($this->buildRequest('skill://git-workflow/SKILL.md'), $this->buildContext());

        self::assertSame([$git->entry], $first->skills);
        self::assertSame('skill://git-workflow/SKILL.md', $first->nextCursor?->cursor);
        self::assertSame([$refunds->entry], $second->skills);
    }

    private function buildRequest(?string $cursor = null): ListSkillsRequest
    {
        return ListSkillsRequest::fromArray([
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
