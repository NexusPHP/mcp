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

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\ToolChoiceMode;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Controls tool selection behavior for sampling requests.
 *
 * @implements Arrayable<array{mode?: value-of<ToolChoiceMode>}>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#toolchoice
 */
final readonly class ToolChoice implements Arrayable
{
    public function __construct(public ?ToolChoiceMode $mode = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $mode = null;

        if (\array_key_exists('mode', $data)) {
            $mode = EnumValueValidator::parse(ToolChoiceMode::class, $data['mode'], '"toolChoice.mode"');
        }

        return new self($mode);
    }

    #[\Override]
    public function toArray(): array
    {
        if (null === $this->mode) {
            return [];
        }

        return ['mode' => $this->mode->value];
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
