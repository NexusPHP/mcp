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

namespace Nexus\Mcp\Tests\Fixtures\Server\Auth;

use Firebase\JWT\Key;

/**
 * Key set listing every key id, failing on the lookup or the read as a `CachedKeySet` can.
 *
 * @implements \ArrayAccess<string, Key>
 */
final readonly class ThrowingKeySet implements \ArrayAccess
{
    public function __construct(
        private ?\Throwable $lookupFailure = null,
        private \Throwable $readFailure = new \LogicException('The key set was read.'),
    ) {
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        if (null !== $this->lookupFailure) {
            throw $this->lookupFailure;
        }

        return true;
    }

    #[\Override]
    public function offsetGet(mixed $offset): Key
    {
        throw $this->readFailure;
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The key set is read-only.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The key set is read-only.');
    }
}
