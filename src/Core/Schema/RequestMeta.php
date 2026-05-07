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

use Nexus\Assert\Assert;

/**
 * The `_meta` extension slot carried by request params. Adds a typed `progressToken`
 * alongside open-ended extras.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#_meta
 */
final readonly class RequestMeta implements Arrayable
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(public ?ProgressToken $progressToken = null, public array $extras = [])
    {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $progressToken = null;

        if (\array_key_exists('progressToken', $data)) {
            $raw = $data['progressToken'];
            Assert::that($raw)->isArrayKey('Progress token must be an int or string, {type} given.');

            $progressToken = new ProgressToken($raw);
            unset($data['progressToken']);
        }

        return new self($progressToken, $data);
    }

    /**
     * Reads the optional `_meta` slot from a parent wire payload, validating
     * its shape. Returns `null` when the key is absent. The `$context` prefix
     * scopes the error message to the calling shape (e.g. `"Request params"`).
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context
     */
    public static function parseFromWire(array $data, string $context): ?self
    {
        if (! \array_key_exists('_meta', $data)) {
            return null;
        }

        Assert::that($data['_meta'])
            ->isArray(\sprintf('%s "_meta" must be an object, {type} given.', $context))
            ->isMap(\sprintf('%s "_meta" must be a string-keyed object.', $context))
        ;

        return self::fromArray($data['_meta']);
    }

    #[\Override]
    public function toArray(): array
    {
        $out = $this->extras;

        if (null !== $this->progressToken) {
            $out['progressToken'] = $this->progressToken->token;
        }

        return $out;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
