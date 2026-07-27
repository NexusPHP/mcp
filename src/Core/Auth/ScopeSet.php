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

namespace Nexus\Mcp\Core\Auth;

/**
 * An ordered, duplicate-free set of OAuth scope values.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-3.3
 */
final readonly class ScopeSet
{
    /**
     * @var list<non-empty-string>
     */
    public array $values;

    /**
     * @param list<non-empty-string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_values(array_unique($values));
    }

    /**
     * Parses a space-delimited `scope` parameter, treating an absent or blank one as the empty set.
     */
    public static function parse(?string $scope): self
    {
        if (null === $scope) {
            return new self();
        }

        $values = [];

        foreach (explode(' ', $scope) as $value) {
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return new self($values);
    }

    /**
     * Accumulates another set onto this one, keeping this set's values first.
     */
    public function mergeWith(self $other): self
    {
        return new self([...$this->values, ...$other->values]);
    }

    public function containsAll(self $other): bool
    {
        foreach ($other->values as $value) {
            if (! \in_array($value, $this->values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renders the set as a `scope` parameter, or `null` when the set is empty and the parameter is omitted.
     */
    public function toParameter(): ?string
    {
        return [] === $this->values ? null : implode(' ', $this->values);
    }
}
