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

namespace Nexus\Mcp\Core\Dispatch;

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers JSON-RPC responses on a transport for the Server and Client dispatchers,
 * demoting the predictable "peer hung up" failure to an info log.
 *
 * @internal
 */
final readonly class ResponseSender
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function send(TransportInterface $transport, JsonRpcMessage $message, string $method): void
    {
        try {
            $transport->send($message);
        } catch (TransportAlreadyClosedException $e) {
            $this->logSkippedDelivery($method, $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to deliver response to transport.',
                ['method' => $method, 'exception' => $e],
            );
        }
    }

    public function logSkippedDelivery(string $method, TransportAlreadyClosedException $exception): void
    {
        $this->logger->info(
            'Skipping response delivery. Transport is closed.',
            ['method' => $method, 'exception' => $exception],
        );
    }

    public static function buildErrorResponse(
        AbstractJsonRpcProtocolException $exception,
        ?RequestId $fallbackId,
    ): JsonRpcErrorResponse {
        return new JsonRpcErrorResponse(
            $exception->requestId ?? $fallbackId,
            ErrorFactory::create($exception::getErrorCode(), $exception->getMessage()),
        );
    }
}
