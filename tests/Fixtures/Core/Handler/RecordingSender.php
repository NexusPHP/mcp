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

namespace Nexus\Mcp\Tests\Fixtures\Core\Handler;

use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;

/**
 * Records every outbound message in-memory for assertion in tests.
 *
 * @internal
 */
final class RecordingSender implements SenderInterface
{
    /**
     * @var list<JsonRpcNotification<non-empty-string>>
     */
    public private(set) array $notifications = [];

    /**
     * @var list<JsonRpcRequest<non-empty-string>>
     */
    public private(set) array $requests = [];

    #[\Override]
    public function sendNotification(JsonRpcNotification $notification): void
    {
        $this->notifications[] = $notification;
    }

    #[\Override]
    public function sendRequest(JsonRpcRequest $request): JsonRpcResultResponse
    {
        $this->requests[] = $request;

        return new GenericResultResponse(id: $request->id, result: new EmptyResult());
    }
}
