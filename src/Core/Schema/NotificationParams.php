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

namespace Nexus\Mcp\Core\Schema;

/**
 * Common params for any notification.
 *
 * @template-covariant T of array<string, mixed> = array<string, mixed>
 *
 * @implements Arrayable<T>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#notificationparams
 */
abstract readonly class NotificationParams implements Arrayable
{
    public function __construct(public MetaObject $meta = new MetaObject())
    {
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
