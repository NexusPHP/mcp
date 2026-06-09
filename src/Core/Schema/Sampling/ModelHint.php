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

namespace Nexus\Mcp\Core\Schema\Sampling;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Hints to use for model selection.
 *
 * Keys not declared here are currently left unspecified by the spec and are up
 * to the client to interpret.
 *
 * @implements Arrayable<array{name?: non-empty-string}>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#modelhint
 */
final readonly class ModelHint implements Arrayable
{
    /**
     * @var null|non-empty-string
     */
    public ?string $name;

    public function __construct(?string $name = null)
    {
        Assert::that($name)->nullOr()->isNonEmptyString('"hints.name" must be a non-empty string or null.');

        $this->name = $name;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? null;
        Assert::that($name)->nullOr()->isString('"hints.name" must be a string or null, {type} given.');

        return new self(name: $name);
    }

    #[\Override]
    public function toArray(): array
    {
        if (null === $this->name) {
            return [];
        }

        return ['name' => $this->name];
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
