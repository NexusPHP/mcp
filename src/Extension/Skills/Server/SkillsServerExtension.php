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

namespace Nexus\Mcp\Extension\Skills\Server;

use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ReadResourceDirectoryRequest;
use Nexus\Mcp\Extension\Skills\Server\Handler\GetSkillRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\ListSkillsRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\ReadResourceDirectoryRequestHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileListResourcesHandler;
use Nexus\Mcp\Extension\Skills\Server\Handler\SkillFileReadResourceHandler;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStoreInterface;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\Extension\OptionalClientDeclarationInterface;
use Nexus\Mcp\Server\Extension\RequestHandlerDecoratorInterface;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;

/**
 * The official skills extension (`io.modelcontextprotocol/skills`, SEP-2640) for the server.
 */
final readonly class SkillsServerExtension implements OptionalClientDeclarationInterface, RequestHandlerDecoratorInterface, ServerExtensionInterface
{
    /**
     * @param bool $directoryRead Whether `resources/directory/read` is served and advertised
     */
    public function __construct(
        private SkillStoreInterface $store,
        private bool $directoryRead = true,
    ) {
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return Skills::IDENTIFIER;
    }

    #[\Override]
    public function getSettings(): array
    {
        return $this->directoryRead ? [Skills::DIRECTORY_READ_SETTING => true] : [];
    }

    #[\Override]
    public function getRequests(): array
    {
        $requests = [ListSkillsRequest::class, GetSkillRequest::class];

        if ($this->directoryRead) {
            $requests[] = ReadResourceDirectoryRequest::class;
        }

        return $requests;
    }

    #[\Override]
    public function getNotifications(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        $handlers = [
            ListSkillsRequest::getMethod() => new ListSkillsRequestHandler($this->store),
            GetSkillRequest::getMethod() => new GetSkillRequestHandler($this->store),
        ];

        if ($this->directoryRead) {
            $handlers[ReadResourceDirectoryRequest::getMethod()] = new ReadResourceDirectoryRequestHandler($this->store);
        }

        return $handlers;
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlerDecorators(): array
    {
        return [
            ListResourcesRequest::getMethod() => fn(RequestHandlerInterface $inner): RequestHandlerInterface => new SkillFileListResourcesHandler($inner, $this->store),
            ReadResourceRequest::getMethod() => fn(RequestHandlerInterface $inner): RequestHandlerInterface => new SkillFileReadResourceHandler($inner, $this->store),
        ];
    }
}
