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

namespace Nexus\Mcp\Core\Schema\Elicitation;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * A single `{const, title}` entry inside a titled enum schema's option list.
 *
 * @implements Arrayable<array{const: non-empty-string, title: non-empty-string}>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
final readonly class EnumOption implements Arrayable
{
    /**
     * @param non-empty-string $const
     * @param non-empty-string $title
     */
    public function __construct(public string $const, public string $title)
    {
        Assert::that($const)->isNonEmptyString('"oneOf.const" must be a non-empty string.');
        Assert::that($title)->isNonEmptyString('"oneOf.title" must be a non-empty string.');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('const', '"oneOf" is missing the required "const" key.');
        $const = $data['const'];
        Assert::that($const)->isNonEmptyString('"oneOf.const" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('title', '"oneOf" is missing the required "title" key.');
        $title = $data['title'];
        Assert::that($title)->isNonEmptyString('"oneOf.title" must be a non-empty string, {type} given.');

        return new self(const: $const, title: $title);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'const' => $this->const,
            'title' => $this->title,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
