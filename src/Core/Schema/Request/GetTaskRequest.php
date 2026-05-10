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
use Nexus\Mcp\Core\Schema\RequestParams\GetTaskRequestParams;

/**
 * A request to retrieve the state of a task.
 *
 * @property-read GetTaskRequestParams $params
 *
 * @extends JsonRpcRequest<'tasks/get'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskrequest
 */
final readonly class GetTaskRequest extends JsonRpcRequest implements ClientRequest, ServerRequest
{
    public function __construct(RequestId $id, GetTaskRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'tasks/get';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'GetTaskRequest wire data missing "id".');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('GetTaskRequest wire "id" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'GetTaskRequest wire data missing "params".');
        Assert::that($data['params'])
            ->isArray('GetTaskRequest wire "params" must be an object, {type} given.')
            ->isMap('GetTaskRequest wire "params" must be a string-keyed object.')
        ;

        return new self(new RequestId($id), GetTaskRequestParams::fromArray($data['params']));
    }
}
