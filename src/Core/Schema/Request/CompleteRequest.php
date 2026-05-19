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
use Nexus\Mcp\Core\Schema\RequestParams\CompleteRequestParams;

/**
 * A request from the client to the server, to ask for completion options.
 *
 * @property-read CompleteRequestParams $params
 *
 * @extends JsonRpcRequest<'completion/complete'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#completerequest
 */
final readonly class CompleteRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, CompleteRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'completion/complete';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('"id" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(new RequestId($id), CompleteRequestParams::fromArray($data['params']));
    }
}
