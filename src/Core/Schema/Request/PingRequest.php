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
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;

/**
 * A ping, issued by either the server or the client, to check that the other party is still alive.
 * The receiver must promptly respond, or else may be disconnected.
 *
 * @extends JsonRpcRequest<'ping'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#pingrequest
 */
final readonly class PingRequest extends JsonRpcRequest implements ClientRequest, ServerRequest
{
    #[\Override]
    public static function method(): string
    {
        return 'ping';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');

        $id = $data['id'];
        Assert::that($id)->isArrayKey('"id" must be int or string, {type} given.');

        $params = new EmptyRequestParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('"params" must be an object, {type} given.')
                ->isMap('"params" must be a string-keyed object.')
            ;
            $params = EmptyRequestParams::fromArray($data['params']);
        }

        return new self(new RequestId($id), $params);
    }
}
