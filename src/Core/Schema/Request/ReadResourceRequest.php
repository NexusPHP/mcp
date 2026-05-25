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
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;

/**
 * Sent from the client to the server, to read a specific resource URI.
 *
 * @property-read ReadResourceRequestParams $params
 *
 * @extends JsonRpcRequest<'resources/read'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#readresourcerequest
 */
final readonly class ReadResourceRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, ReadResourceRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'resources/read';
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
            ReadResourceRequestParams::fromArray($data['params']),
        );
    }
}
