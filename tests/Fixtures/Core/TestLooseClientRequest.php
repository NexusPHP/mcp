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

/**
 * A client-sendable vendor request whose `fromArray` tolerates absent params,
 * for exercising the dispatcher's params-contract guard.
 *
 * @internal
 *
 * @extends JsonRpcRequest<'tests/test-loose-client-request', array<string, mixed>>
 */
final readonly class TestLooseClientRequest extends JsonRpcRequest implements ClientRequest
{
    public function __construct(RequestId $id)
    {
        parent::__construct(id: $id, params: null);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tests/test-loose-client-request';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isIntOrNonEmptyString('"id" must be an int or non-empty string, {type} given.');

        return new self(new RequestId(id: $id));
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'method' => static::getMethod(),
        ];
    }
}
