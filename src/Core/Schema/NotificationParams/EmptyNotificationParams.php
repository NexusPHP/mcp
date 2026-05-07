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

namespace Nexus\Mcp\Core\Schema\NotificationParams;

use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\NotificationParams;

/**
 * Default notification params for methods that carry no typed fields beyond `_meta`.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class EmptyNotificationParams extends NotificationParams
{
    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = Meta::parseFromWire($data, 'Notification params');

        return new self($meta);
    }
}
