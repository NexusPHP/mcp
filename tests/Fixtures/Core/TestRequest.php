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
use Nexus\Mcp\Core\Schema\RequestParamsInterface;

/**
 * A request bound to a vendor method literal absent from the default registry,
 * used to exercise user-supplied method-map parsing and handler dispatch.
 *
 * @internal
 *
 * @extends JsonRpcRequest<'tests/test-request'>
 */
final readonly class TestRequest extends JsonRpcRequest
{
    /**
     * @param null|RequestParamsInterface<array<string, mixed>> $params
     */
    public function __construct(RequestId $id, ?RequestParamsInterface $params = null)
    {
        parent::__construct($id, $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tests/test-request';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;

        if (! \is_int($id) && ! \is_string($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'TestRequest "id" must be an int or string, %s given.',
                get_debug_type($id),
            ));
        }

        return new self(new RequestId($id));
    }
}
