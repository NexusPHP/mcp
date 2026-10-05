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

namespace Nexus\Mcp\Extension\Skills\Server\Handler;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `skills/list` request by delegating to a `SkillStoreInterface`.
 *
 * @implements RequestHandlerInterface<'skills/list', ListSkillsResult, ServerContext>
 */
final readonly class ListSkillsRequestHandler implements RequestHandlerInterface
{
    public function __construct(private SkillStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): ListSkillsResult
    {
        \assert($request instanceof ListSkillsRequest);

        return $this->store->list($request->params->cursor);
    }
}
