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

use Amp\DeferredFuture;
use Amp\Future;
use Nexus\Mcp\Core\Exception\DuplicateOutboundRequestIdException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Correlates outbound JSON-RPC requests with their inbound responses. Senders
 * register a `RequestId` and receive a `Future` that resolves once a matching
 * response arrives on the transport.
 *
 * @internal
 *
 * @see PendingInboundRequests
 */
final class PendingOutboundRequests implements \Countable
{
    /**
     * @var array<non-empty-string, array{deferred: DeferredFuture<JsonRpcResultResponse<array<string, mixed>>>, response: class-string<JsonRpcResultResponse<array<string, mixed>>>}>
     */
    private array $map = [];

    /**
     * Registers an outbound request id and returns the future that resolves
     * once `resolve()` or `reject()` is called for the same id.
     *
     * @template TResponse of JsonRpcResultResponse<array<string, mixed>> = JsonRpcResultResponse<array<string, mixed>>
     *
     * @param class-string<TResponse> $response
     *
     * @return Future<TResponse>
     *
     * @throws DuplicateOutboundRequestIdException
     */
    public function register(RequestId $id, string $response): Future
    {
        $key = self::buildKey($id);

        if (\array_key_exists($key, $this->map)) {
            throw new DuplicateOutboundRequestIdException($id);
        }

        /** @var DeferredFuture<TResponse> $deferred */
        $deferred = new DeferredFuture();
        $this->map[$key] = ['deferred' => $deferred, 'response' => $response];

        return $deferred->getFuture();
    }

    /**
     * Returns the response envelope class registered for `$id`, or `null` if no
     * entry exists.
     *
     * @return null|class-string<JsonRpcResultResponse<array<string, mixed>>>
     */
    public function resolveResponseClass(RequestId $id): ?string
    {
        $key = self::buildKey($id);

        if (! \array_key_exists($key, $this->map)) {
            return null;
        }

        return $this->map[$key]['response'];
    }

    /**
     * Completes the future for `$id` with the given response. Returns false
     * if no entry was registered for that id.
     *
     * @param JsonRpcResultResponse<array<string, mixed>> $response
     */
    public function resolve(RequestId $id, JsonRpcResultResponse $response): bool
    {
        $key = self::buildKey($id);

        if (! \array_key_exists($key, $this->map)) {
            return false;
        }

        $deferred = $this->map[$key]['deferred'];
        unset($this->map[$key]);
        $deferred->complete($response);

        return true;
    }

    /**
     * Fails the future for `$id` with the given error. Returns false if no
     * entry was registered for that id.
     */
    public function reject(RequestId $id, \Throwable $error): bool
    {
        $key = self::buildKey($id);

        if (! \array_key_exists($key, $this->map)) {
            return false;
        }

        $deferred = $this->map[$key]['deferred'];
        unset($this->map[$key]);
        $deferred->error($error);

        return true;
    }

    /**
     * Removes the entry for `$id` without completing its future. Returns false
     * if no entry was registered for that id.
     */
    public function forget(RequestId $id): bool
    {
        $key = self::buildKey($id);

        if (! \array_key_exists($key, $this->map)) {
            return false;
        }

        unset($this->map[$key]);

        return true;
    }

    /**
     * Fails every pending future with the given error and empties the map.
     */
    public function cancelAll(\Throwable $error): void
    {
        $pending = $this->map;
        $this->map = [];

        foreach ($pending as $entry) {
            $entry['deferred']->error($error);
        }
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->map);
    }

    /**
     * @return non-empty-string
     */
    private static function buildKey(RequestId $id): string
    {
        return \sprintf('"id":%s', var_export($id->id, true));
    }
}
