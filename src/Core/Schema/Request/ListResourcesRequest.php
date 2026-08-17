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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;

/**
 * Sent from the client to request a list of resources the server has.
 *
 * @property-read PaginatedRequestParams $params
 *
 * @extends PaginatedRequest<'resources/list', array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   method: 'resources/list',
 *   params: template-type<PaginatedRequestParams, RequestParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#listresourcesrequest
 */
final readonly class ListResourcesRequest extends PaginatedRequest implements ClientRequest
{
    public function __construct(RequestId $id, PaginatedRequestParams $params)
    {
        parent::__construct(id: $id, params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'resources/list';
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
        $params = PaginatedRequestParams::fromArray($data['params']);

        return new self(id: new RequestId(id: $id), params: $params);
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
