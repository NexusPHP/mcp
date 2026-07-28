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

namespace Nexus\Mcp\Core\Schema\Request;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\SubscriptionsListenRequestParams;

/**
 * Sent from the client to open a long-lived channel for receiving notifications outside the
 * context of a specific request. Replaces the previous HTTP GET endpoint and ensures
 * consistent behavior between HTTP and STDIO.
 *
 * @property-read SubscriptionsListenRequestParams $params
 *
 * @extends JsonRpcRequest<'subscriptions/listen', array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   method: 'subscriptions/listen',
 *   params: template-type<SubscriptionsListenRequestParams, RequestParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#subscriptionslistenrequest
 */
final readonly class SubscriptionsListenRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, SubscriptionsListenRequestParams $params)
    {
        parent::__construct(id: $id, params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'subscriptions/listen';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('"id" must be an int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(
            id: new RequestId(id: $id),
            params: SubscriptionsListenRequestParams::fromArray($data['params']),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => static::getMethod(),
            'params' => $this->params->toArray(),
        ];
    }
}
