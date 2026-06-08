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
 * Sent from the server to request a list of root URIs from the client. Roots allow servers to
 * ask for specific directories or files to operate on. A common example for roots is providing
 * a set of repositories or directories a server should operate on.
 *
 * This request is typically used when the server needs to understand the file system structure
 * or access specific locations that the client has permission to read from.
 *
 * @extends JsonRpcRequest<'roots/list'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listrootsrequest
 */
final readonly class ListRootsRequest extends JsonRpcRequest implements ServerRequest
{
    #[\Override]
    public static function getMethod(): string
    {
        return 'roots/list';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('"id" must be an int or string, {type} given.');

        $params = null;

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
