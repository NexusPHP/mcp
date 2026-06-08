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

namespace Nexus\Mcp\Core\Schema\JsonRpc;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParamsInterface;

/**
 * A request that expects a response.
 *
 * @template-covariant TMethod of non-empty-string
 *
 * @extends Request<TMethod>
 * @implements Arrayable<array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   method: non-empty-string,
 *   params?: array<string, mixed>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#jsonrpcrequest
 */
abstract readonly class JsonRpcRequest extends Request implements Arrayable, JsonRpcMessage
{
    /**
     * @param null|RequestParamsInterface<array<string, mixed>> $params
     */
    public function __construct(public RequestId $id, ?RequestParamsInterface $params = null)
    {
        parent::__construct($params);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    #[\Override]
    public function toArray(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => static::getMethod(),
        ];

        if (null !== $this->params) {
            $envelope['params'] = $this->params->toArray();
        }

        return $envelope;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => static::getMethod(),
        ];

        if (null !== $this->params) {
            $envelope['params'] = $this->params->jsonSerialize();
        }

        return $envelope;
    }
}
