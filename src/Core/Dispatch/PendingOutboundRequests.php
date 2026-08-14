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
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;

/**
 * Correlation map from outbound JSON-RPC requests to their inbound responses.
 *
 * @internal
 *
 * @see PendingInboundRequests
 */
final class PendingOutboundRequests implements \Countable
{
    /**
     * @var array<non-empty-string, array{
     *   deferred: DeferredFuture<JsonRpcResultResponse>,
     *   response: class-string<JsonRpcResultResponse>,
     *   request: null|JsonRpcRequest<non-empty-string>,
     *   context: null|SendContext,
     * }>
     */
    private array $map = [];

    /**
     * Retains `$request` to mark the entry as one to send again on peer loss.
     *
     * @template TResponse of JsonRpcResultResponse = JsonRpcResultResponse
     *
     * @param class-string<TResponse>               $response
     * @param null|JsonRpcRequest<non-empty-string> $request
     *
     * @return Future<TResponse>
     *
     * @throws LogicException
     */
    public function register(
        RequestId $id,
        string $response,
        ?JsonRpcRequest $request = null,
        ?SendContext $context = null,
    ): Future {
        $key = self::buildKey($id);

        if (\array_key_exists($key, $this->map)) {
            throw new LogicException(\sprintf(
                'Outbound request id %s is already pending. The id-generation strategy must produce unique ids per in-flight request.',
                var_export($id->id, true),
            ));
        }

        /** @var DeferredFuture<TResponse> $deferred */
        $deferred = new DeferredFuture();
        $this->map[$key] = [
            'deferred' => $deferred,
            'response' => $response,
            'request' => $request,
            'context' => $context,
        ];

        return $deferred->getFuture();
    }

    /**
     * @return null|class-string<JsonRpcResultResponse>
     */
    public function resolveResponseClass(RequestId $id): ?string
    {
        return $this->map[self::buildKey($id)]['response'] ?? null;
    }

    /**
     * Completes the future for `$id` with the given response, returning false if no entry was registered for it.
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
     * Fails the future for `$id` with the given error, returning false if no entry was registered for it.
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
     * Removes the entry for `$id` without completing its future, returning false if no entry was registered for it.
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

    public function cancelAll(\Throwable $error): void
    {
        $pending = $this->map;
        $this->map = [];

        foreach ($pending as $entry) {
            $entry['deferred']->error($error);
        }
    }

    /**
     * Fails every pending future that retained no request, leaving the rest registered for a caller that
     * means to send them again.
     */
    public function cancelUnretained(\Throwable $error): void
    {
        $failed = [];

        foreach ($this->map as $key => $entry) {
            if (null === $entry['request']) {
                $failed[] = $entry['deferred'];
                unset($this->map[$key]);
            }
        }

        foreach ($failed as $deferred) {
            $deferred->error($error);
        }
    }

    /**
     * Every entry still pending that retained a request, in registration order.
     *
     * @return list<array{request: JsonRpcRequest<non-empty-string>, context: null|SendContext}>
     */
    public function collectRetained(): array
    {
        $retained = [];

        foreach ($this->map as $entry) {
            $request = $entry['request'];

            if (null !== $request) {
                $retained[] = ['request' => $request, 'context' => $entry['context']];
            }
        }

        return $retained;
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
