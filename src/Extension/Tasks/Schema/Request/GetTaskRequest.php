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

namespace Nexus\Mcp\Extension\Tasks\Schema\Request;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\GetTaskRequestParams;

/**
 * Sent from the client to the server, to poll the current state of a task.
 *
 * @property-read GetTaskRequestParams $params
 *
 * @extends JsonRpcRequest<'tasks/get', array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   method: 'tasks/get',
 *   params: template-type<GetTaskRequestParams, RequestParams, 'T'>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class GetTaskRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, GetTaskRequestParams $params)
    {
        parent::__construct(id: $id, params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tasks/get';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isIntOrNonEmptyString('"id" must be an int or non-empty string, {type} given.');

        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(
            id: new RequestId(id: $id),
            params: GetTaskRequestParams::fromArray($data['params']),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => self::getMethod(),
            'params' => $this->params->toArray(),
        ];
    }
}
