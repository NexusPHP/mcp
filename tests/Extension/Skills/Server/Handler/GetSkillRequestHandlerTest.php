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
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\GetSkillRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Extension\Skills\ArraySkillProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetSkillRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetSkillRequestHandlerTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';

    public function testTheSkillTheUriNamesIsServed(): void
    {
        $refunds = new DirectorySkill('refunds', self::FIXTURES.'/refunds');
        $handler = new GetSkillRequestHandler(new SkillStore([$refunds]));

        $result = $handler->handle($this->buildRequest('skill://refunds/SKILL.md'), $this->buildContext());

        self::assertSame($refunds->entry, $result->skill);
    }

    public function testTheContextReachesTheProvider(): void
    {
        $entry = new Skill('skill://reports/q3/SKILL.md', ['name' => 'q3', 'description' => 'Quarterly report.'], 'dynamic');
        $provider = new ArraySkillProvider(skills: [$entry->uri => $entry]);
        $handler = new GetSkillRequestHandler(new SkillStore(provider: $provider));

        self::assertSame($entry, $handler->handle($this->buildRequest($entry->uri), $this->buildContext())->skill);
        self::assertSame([['findSkill', $entry->uri]], $provider->lookups);
    }

    public function testAnUnknownSkillIsInvalidParamsNamingTheRequest(): void
    {
        $handler = new GetSkillRequestHandler(new SkillStore());

        try {
            $handler->handle($this->buildRequest('skill://missing/SKILL.md'), $this->buildContext());
            self::fail('The lookup did not throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('Resource "skill://missing/SKILL.md" not found.', $e->getMessage());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    /**
     * @param non-empty-string $uri
     */
    private function buildRequest(string $uri): GetSkillRequest
    {
        return GetSkillRequest::fromArray([
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
