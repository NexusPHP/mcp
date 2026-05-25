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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;

/**
 * @internal
 *
 * @extends JsonRpcRequest<'ping'>
 */
final readonly class TestPingOverride extends JsonRpcRequest
{
    public function __construct(RequestId $id, EmptyRequestParams $params = new EmptyRequestParams())
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'ping';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;

        if (! \is_int($id) && ! \is_string($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'TestPingOverride "id" must be an int or string, %s given.',
                get_debug_type($id),
            ));
        }

        return new self(new RequestId($id));
    }
}
