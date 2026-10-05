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

namespace Nexus\Mcp\Tests\Extension\Skills\Server;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ReadResourceDirectoryRequest;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\Handler\GetSkillRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\ListSkillsRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\ReadResourceDirectoryRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileListResourcesHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileReadResourceHandler;
use Nexus\Mcp\Extension\Skills\Server\SkillsServerExtension;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillsServerExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillsServerExtensionTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Extension/Skills';

    public function testDeclaresTheOfficialIdentifierAndAdvertisesDirectoryRead(): void
    {
        $extension = new SkillsServerExtension(new SkillStore());

        self::assertSame('io.modelcontextprotocol/skills', $extension->getIdentifier());
        self::assertSame(['directoryRead' => true], $extension->getSettings());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testServesTheThreeSkillMethodsFromTheStore(): void
    {
        $extension = new SkillsServerExtension($this->buildStore());

        self::assertSame([ListSkillsRequest::class, GetSkillRequest::class, ReadResourceDirectoryRequest::class], $extension->getRequests());

        $handlers = $extension->getRequestHandlers();
        self::assertSame(['skills/list', 'skills/get', 'resources/directory/read'], array_keys($handlers));
        self::assertInstanceOf(ListSkillsRequestHandler::class, $handlers['skills/list'] ?? null);
        self::assertInstanceOf(GetSkillRequestHandler::class, $handlers['skills/get'] ?? null);
        self::assertInstanceOf(ReadResourceDirectoryRequestHandler::class, $handlers['resources/directory/read'] ?? null);

        $context = $this->buildContext();
        $meta = ['_meta' => RequestMetaObjectFactory::shape()];
        $listed = $handlers['skills/list']->handle(ListSkillsRequest::fromArray(['id' => 7, 'params' => $meta]), $context);
        $fetched = $handlers['skills/get']->handle(
            GetSkillRequest::fromArray(['id' => 7, 'params' => $meta + ['uri' => 'skill://refunds/SKILL.md']]),
            $context,
        );
        $directory = $handlers['resources/directory/read']->handle(
            ReadResourceDirectoryRequest::fromArray(['id' => 7, 'params' => $meta + ['uri' => 'skill://refunds']]),
            $context,
        );

        self::assertCount(1, $listed->skills);
        self::assertSame('refunds', $fetched->skill->frontmatter['name']);
        self::assertCount(1, $directory->resources);
    }

    public function testDirectoryReadCanBeLeftOut(): void
    {
        $extension = new SkillsServerExtension(new SkillStore(), directoryRead: false);

        self::assertSame([], $extension->getSettings());
        self::assertSame([ListSkillsRequest::class, GetSkillRequest::class], $extension->getRequests());
        self::assertSame(['skills/list', 'skills/get'], array_keys($extension->getRequestHandlers()));
    }

    public function testDecoratesTheResourceMethodsWithTheStore(): void
    {
        $extension = new SkillsServerExtension($this->buildStore());
        $decorators = $extension->getRequestHandlerDecorators();
        $inner = new ClosureRequestHandler(
            static fn(): Result => new ListResourcesResult([new Resource('notes', 'file:///notes.txt')], 0, CacheScope::Private),
        );

        self::assertSame(['resources/list', 'resources/read'], array_keys($decorators));

        $list = ($decorators['resources/list'] ?? self::fail('The skills extension must decorate "resources/list".'))($inner);
        $read = ($decorators['resources/read'] ?? self::fail('The skills extension must decorate "resources/read".'))($inner);

        self::assertInstanceOf(SkillFileListResourcesHandler::class, $list);
        self::assertInstanceOf(SkillFileReadResourceHandler::class, $read);

        $context = $this->buildContext();
        $meta = ['_meta' => RequestMetaObjectFactory::shape()];
        $listed = $list->handle(ListResourcesRequest::fromArray(['id' => 7, 'params' => $meta]), $context);
        $file = $read->handle(
            ReadResourceRequest::fromArray(['id' => 7, 'params' => $meta + ['uri' => 'skill://refunds/SKILL.md']]),
            $context,
        );

        self::assertInstanceOf(ListResourcesResult::class, $listed);
        self::assertSame(
            ['file:///notes.txt', 'skill://refunds/SKILL.md'],
            array_map(static fn(Resource $resource): string => $resource->uri, $listed->resources),
        );
        self::assertInstanceOf(ReadResourceResult::class, $file);
    }

    private function buildStore(): SkillStore
    {
        return new SkillStore([new DirectorySkill('refunds', self::FIXTURES.'/refunds')]);
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
