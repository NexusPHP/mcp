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
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Decorates the `resources/read` handler so a skill file is served from the skill store.
 *
 * @internal
 *
 * @implements RequestHandlerInterface<'resources/read', Result, ServerContext>
 */
final readonly class SkillFileReadResourceHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $inner
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private SkillStoreInterface $store,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result
    {
        \assert($request instanceof ReadResourceRequest);
        \assert($context instanceof ServerContext);

        return $this->store->readFile($request->params->uri, $context) ?? $this->inner->handle($request, $context);
    }
}
