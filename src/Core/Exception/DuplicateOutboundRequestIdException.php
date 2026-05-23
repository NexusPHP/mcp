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

namespace Nexus\Mcp\Core\Exception;

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when an outbound request reuses an id whose response is still pending.
 */
final class DuplicateOutboundRequestIdException extends \LogicException implements McpExceptionInterface
{
    public function __construct(RequestId $id)
    {
        parent::__construct(\sprintf(
            'Outbound request id %s is already pending. The id-generation strategy must produce unique ids per in-flight request.',
            var_export($id->id, true),
        ));
    }
}
