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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#meta
 *
 * @implements Arrayable<array<string, mixed>>
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
    public static function fromArray(array $data): self
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
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
