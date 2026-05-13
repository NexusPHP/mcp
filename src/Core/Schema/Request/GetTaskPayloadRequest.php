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
use Nexus\Mcp\Core\Schema\RequestParams\GetTaskPayloadRequestParams;

/**
 * A request to retrieve the result of a completed task.
 *
 * @property-read GetTaskPayloadRequestParams $params
 *
 * @extends JsonRpcRequest<'tasks/result'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskpayloadrequest
 */
final readonly class GetTaskPayloadRequest extends JsonRpcRequest implements ClientRequest, ServerRequest
{
    public function __construct(RequestId $id, GetTaskPayloadRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'tasks/result';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'GetTaskPayloadRequest data missing "id".');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('GetTaskPayloadRequest "id" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'GetTaskPayloadRequest data missing "params".');
        Assert::that($data['params'])
            ->isArray('GetTaskPayloadRequest "params" must be an object, {type} given.')
            ->isMap('GetTaskPayloadRequest "params" must be a string-keyed object.')
        ;

        return new self(new RequestId($id), GetTaskPayloadRequestParams::fromArray($data['params']));
    }
}
