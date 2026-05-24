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
use Nexus\Mcp\Core\Schema\RequestParams\UnsubscribeRequestParams;

/**
 * Sent from the client to request cancellation of resources/updated notifications from the
 * server. This should follow a previous resources/subscribe request.
 *
 * @property-read UnsubscribeRequestParams $params
 *
 * @extends JsonRpcRequest<'resources/unsubscribe'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#unsubscriberequest
 */
final readonly class UnsubscribeRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, UnsubscribeRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'resources/unsubscribe';
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
            new RequestId($id),
            UnsubscribeRequestParams::fromArray($data['params']),
        );
    }
}
