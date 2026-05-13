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

namespace Nexus\Mcp\Core\Handler\Request;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;

/**
 * Acknowledges a `ping` with an empty result. Bidirectional per spec: both
 * server and client register this handler.
 *
 * @implements RequestHandlerInterface<'ping', EmptyResult, AbstractContext>
 */
final readonly class PingRequestHandler implements RequestHandlerInterface
{
    public function __construct(private MetaObject $meta = new MetaObject())
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): EmptyResult
    {
        return new EmptyResult($this->meta);
    }
}
