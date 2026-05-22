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
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;

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
     * @var array<non-empty-string, array{deferred: DeferredFuture<JsonRpcResultResponse<Result>>, result: class-string<Result>}>
     */
    private array $map = [];

    /**
     * Registers an outbound request id and returns the future that resolves
     * once `resolve()` or `reject()` is called for the same id.
     *
     * @template T of Result
     *
     * @param class-string<T> $result
     *
     * @return Future<JsonRpcResultResponse<T>>
     *
     * @throws \LogicException
     */
    public function register(RequestId $id, string $result): Future
    {
        $key = self::key($id);

        if (\array_key_exists($key, $this->map)) {
            throw new \LogicException(\sprintf(
                'Outbound request id %s is already pending. The id-generation strategy must produce unique ids per in-flight request.',
                var_export($id->id, true),
            ));
        }

        /** @var DeferredFuture<JsonRpcResultResponse<T>> $deferred */
        $deferred = new DeferredFuture();
        $this->map[$key] = ['deferred' => $deferred, 'result' => $result];

        return $deferred->getFuture();
    }

    /**
     * Returns the `Result` subclass registered for `$id`, or `null` if no
     * entry exists.
     *
     * @return null|class-string<Result>
     */
    public function resultClassFor(RequestId $id): ?string
    {
        $key = self::key($id);

        if (! \array_key_exists($key, $this->map)) {
            return null;
        }

        return $this->map[$key]['result'];
    }

    /**
     * Completes the future for `$id` with the given response. Returns false
     * if no entry was registered for that id.
     *
     * @param JsonRpcResultResponse<Result> $response
     */
    public function resolve(RequestId $id, JsonRpcResultResponse $response): bool
    {
        $key = self::key($id);

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
        $key = self::key($id);

        if (! \array_key_exists($key, $this->map)) {
            return false;
        }

        $deferred = $this->map[$key]['deferred'];
        unset($this->map[$key]);
        $deferred->error($error);

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
    private static function key(RequestId $id): string
    {
        return \sprintf('"id":%s', var_export($id->id, true));
    }
}
