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

use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * The `_meta` of a payload nested inside an envelope rather than of the envelope
 * itself, such as a tool, a resource, a prompt, or a content block. It reserves no
 * keys of its own, so every entry is an extra.
 *
 * @extends MetaObject<array<string, mixed>>
 */
final readonly class PayloadMetaObject extends MetaObject
{
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(extras: $data);
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->extras;
    }
}
