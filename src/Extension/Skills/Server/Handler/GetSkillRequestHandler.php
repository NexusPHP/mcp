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
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Result\GetSkillResult;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `skills/get` request by delegating to a `SkillStoreInterface`.
 *
 * @implements RequestHandlerInterface<'skills/get', GetSkillResult, ServerContext>
 */
final readonly class GetSkillRequestHandler implements RequestHandlerInterface
{
    public function __construct(private SkillStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): GetSkillResult
    {
        \assert($request instanceof GetSkillRequest);
        \assert($context instanceof ServerContext);

        return $this->store->get($request->params->uri, $context);
    }
}
