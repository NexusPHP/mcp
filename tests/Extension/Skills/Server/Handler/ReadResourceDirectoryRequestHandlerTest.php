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
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Extension\Skills\Schema\Request\ReadResourceDirectoryRequest;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\ReadResourceDirectoryRequestHandler;
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
#[CoversClass(ReadResourceDirectoryRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ReadResourceDirectoryRequestHandlerTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';

    public function testThePageTheCursorNamesIsServed(): void
    {
        $handler = new ReadResourceDirectoryRequestHandler(new SkillStore(
            [new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow')],
            pageSize: 3,
        ));

        $first = $handler->handle($this->buildRequest('skill://git-workflow'), $this->buildContext());
        $second = $handler->handle($this->buildRequest('skill://git-workflow', 'skill://git-workflow/scripts'), $this->buildContext());

        self::assertSame(
            ['git-workflow', 'references', 'scripts'],
            array_map(static fn(Resource $resource): string => $resource->name, $first->resources),
        );
        self::assertSame('skill://git-workflow/scripts', $first->nextCursor?->cursor);
        self::assertSame(['templates'], array_map(static fn(Resource $resource): string => $resource->name, $second->resources));
    }

    public function testTheContextReachesTheProvider(): void
    {
        $child = new Resource('chart.png', 'skill://reports/q3/chart.png', mimeType: 'image/png');
        $provider = new ArraySkillProvider(directories: ['skill://reports/q3' => [$child]]);
        $handler = new ReadResourceDirectoryRequestHandler(new SkillStore(provider: $provider));

        self::assertSame([$child], $handler->handle($this->buildRequest('skill://reports/q3'), $this->buildContext())->resources);
        self::assertSame([['findDirectory', 'skill://reports/q3']], $provider->lookups);
    }

    public function testAnUnknownDirectoryIsInvalidParamsNamingTheRequest(): void
    {
        $handler = new ReadResourceDirectoryRequestHandler(new SkillStore());

        try {
            $handler->handle($this->buildRequest('skill://missing'), $this->buildContext());
            self::fail('The lookup did not throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('Resource "skill://missing" not found.', $e->getMessage());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    /**
     * @param non-empty-string $uri
     */
    private function buildRequest(string $uri, ?string $cursor = null): ReadResourceDirectoryRequest
    {
        return ReadResourceDirectoryRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'uri' => $uri] + (null === $cursor ? [] : ['cursor' => $cursor]),
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
