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

namespace Nexus\Mcp\Core\Schema\MetaObject;

/**
 * The `_meta` of a result reserving no keys beyond the ones every result may carry.
 */
final readonly class GenericResultMetaObject extends ResultMetaObject
{
    #[\Override]
    public static function fromArray(array $data): static
    {
        [$serverInfo, $extras] = self::splitServerInfo($data);

        return new self(serverInfo: $serverInfo, extras: $extras);
    }
}
