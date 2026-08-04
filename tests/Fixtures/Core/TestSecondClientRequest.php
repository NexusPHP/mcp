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

namespace Nexus\Mcp\Tests\Fixtures\Core;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;

/**
 * A second client-sendable vendor request, for tests that need two distinct
 * dispatchable vendor methods side by side.
 *
 * @internal
 *
 * @property-read EmptyRequestParams $params
 *
 * @extends JsonRpcRequest<'tests/test-second-client-request', array<string, mixed>>
 */
final readonly class TestSecondClientRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, EmptyRequestParams $params)
    {
        parent::__construct(id: $id, params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tests/test-second-client-request';
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
        $params = EmptyRequestParams::fromArray($data['params']);

        return new self(id: new RequestId(id: $id), params: $params);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => static::getMethod(),
            'params' => $this->params->toArray(),
        ];
    }
}
