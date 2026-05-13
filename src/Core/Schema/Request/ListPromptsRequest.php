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
use Nexus\Mcp\Core\Schema\JsonRpc\PaginatedRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;

/**
 * Sent from the client to request a list of prompts and prompt templates the server has.
 *
 * @property-read PaginatedRequestParams $params
 *
 * @extends PaginatedRequest<'prompts/list'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listpromptsrequest
 */
final readonly class ListPromptsRequest extends PaginatedRequest implements ClientRequest
{
    public function __construct(RequestId $id, PaginatedRequestParams $params = new PaginatedRequestParams())
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'prompts/list';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'ListPromptsRequest data missing "id".');
        $id = $data['id'];
        Assert::that($id)->isArrayKey('ListPromptsRequest "id" must be int or string, {type} given.');

        $params = new PaginatedRequestParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('ListPromptsRequest "params" must be an object, {type} given.')
                ->isMap('ListPromptsRequest "params" must be a string-keyed object.')
            ;
            $params = PaginatedRequestParams::fromArray($data['params']);
        }

        return new self(new RequestId($id), $params);
    }
}
