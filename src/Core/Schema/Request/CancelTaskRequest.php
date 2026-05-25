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
use Nexus\Mcp\Core\Schema\RequestParams\CancelTaskRequestParams;

/**
 * A request to cancel a task.
 *
 * @property-read CancelTaskRequestParams $params
 *
 * @extends JsonRpcRequest<'tasks/cancel'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#canceltaskrequest
 */
final readonly class CancelTaskRequest extends JsonRpcRequest implements ClientRequest, ServerRequest
{
    public function __construct(RequestId $id, CancelTaskRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tasks/cancel';
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

        return new self(new RequestId($id), CancelTaskRequestParams::fromArray($data['params']));
    }
}
