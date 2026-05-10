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
use Nexus\Mcp\Core\Schema\RequestParams\CreateMessageRequestParams;

/**
 * A request from the server to sample an LLM via the client. The client has full discretion over
 * which model to select. The client should also inform the user before beginning sampling, to
 * allow them to inspect the request (human in the loop) and decide whether to approve it.
 *
 * @property-read CreateMessageRequestParams $params
 *
 * @extends JsonRpcRequest<'sampling/createMessage'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#createmessagerequest
 */
final readonly class CreateMessageRequest extends JsonRpcRequest implements ServerRequest
{
    public function __construct(RequestId $id, CreateMessageRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'sampling/createMessage';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'CreateMessageRequest wire data missing "id".');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('CreateMessageRequest wire "id" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'CreateMessageRequest wire data missing "params".');
        Assert::that($data['params'])
            ->isArray('CreateMessageRequest wire "params" must be an object, {type} given.')
            ->isMap('CreateMessageRequest wire "params" must be a string-keyed object.')
        ;

        return new self(new RequestId($id), CreateMessageRequestParams::fromArray($data['params']));
    }
}
