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
use Nexus\Mcp\Core\Schema\RequestParams\InitializeRequestParams;

/**
 * This request is sent from the client to the server when it first connects, asking it to begin initialization.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle
 *
 * @extends JsonRpcRequest<'initialize'>
 */
final readonly class InitializeRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id, InitializeRequestParams $params)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'initialize';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'InitializeRequest wire data missing "id".');

        $id = $data['id'];
        Assert::that($id)->isArrayKey('InitializeRequest wire "id" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('params', 'InitializeRequest wire data missing "params".');
        Assert::that($data['params'])
            ->isArray('InitializeRequest wire "params" must be an object, {type} given.')
            ->isMap('InitializeRequest wire "params" must be a string-keyed object.')
        ;

        return new self(
            new RequestId($id),
            InitializeRequestParams::fromArray($data['params']),
        );
    }
}
