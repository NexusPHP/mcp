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
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;

/**
 * A request from the server to elicit additional information from the user via the client.
 *
 * @property-read ElicitRequestFormParams|ElicitRequestUrlParams $params
 *
 * @extends JsonRpcRequest<'elicitation/create'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#elicitrequest
 */
final readonly class ElicitRequest extends JsonRpcRequest implements ServerRequest
{
    public function __construct(RequestId $id, ElicitRequestFormParams|ElicitRequestUrlParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'elicitation/create';
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

        $mode = $data['params']['mode'] ?? ElicitRequestFormParams::MODE;

        $params = ElicitRequestUrlParams::MODE === $mode
            ? ElicitRequestUrlParams::fromArray($data['params'])
            : ElicitRequestFormParams::fromArray($data['params']);

        return new self(new RequestId($id), $params);
    }
}
