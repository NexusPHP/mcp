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

use Nexus\Assert\Assert;

/**
 * An ordered, duplicate-free set of OAuth scope values.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-3.3
 */
final readonly class ScopeSet
{
    /**
     * The scope that asks an authorization server for a refresh token.
     *
     * @see https://openid.net/specs/openid-connect-core-1_0.html#OfflineAccess
     */
    public const string OFFLINE_ACCESS = 'offline_access';

    /**
     * RFC 6749 section 3.3 `scope-token` syntax (`1*( %x21 / %x23-5B / %x5D-7E )`).
     */
    private const string SCOPE_TOKEN_PATTERN = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';

    /**
     * @var list<non-empty-string>
     */
    public array $values;

    /**
     * @param list<non-empty-string> $values
     */
    public function __construct(array $values = [])
    {
        Assert::that($values)->values()->isNonEmptyString('Each scope must be a non-empty string, {type} given.');

        $this->values = array_values(array_unique($values));
    }

    /**
     * Parses a space-delimited `scope` parameter, treating an absent or blank one as the empty set.
     *
     * A segment that is not an RFC 6749 `scope-token` is dropped, so never build the *required* side of a
     * `containsAll()` grant decision from this.
     */
    public static function parse(?string $scope): self
    {
        return null === $scope ? new self() : self::fromList(explode(' ', $scope));
    }

    /**
     * Builds a set from peer-supplied values, dropping each that is not an RFC 6749 `scope-token`.
     *
     * @param list<string> $values
     */
    public static function fromList(array $values): self
    {
        $kept = [];

        foreach ($values as $value) {
            if (preg_match(self::SCOPE_TOKEN_PATTERN, $value) === 1) {
                $kept[] = $value;
            }
        }

        return new self($kept);
    }

    /**
     * Accumulates another set onto this one, keeping this set's values first.
     */
    public function mergeWith(self $other): self
    {
        return new self([...$this->values, ...$other->values]);
    }

    public function contains(string $scope): bool
    {
        return \in_array($scope, $this->values, true);
    }

    /**
     * This set with one value removed, whether or not it was present.
     */
    public function without(string $scope): self
    {
        return new self(array_values(array_filter($this->values, static fn(string $value): bool => $value !== $scope)));
    }

    public function containsAll(self $other): bool
    {
        foreach ($other->values as $value) {
            if (! $this->contains($value)) {
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
