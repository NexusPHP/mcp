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
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class EnumOption implements Arrayable
{
    /**
     * @var non-empty-string
     */
    public string $const;

    /**
     * @var non-empty-string
     */
    public string $title;

    public function __construct(string $const, string $title)
    {
        Assert::that($const)->isNonEmptyString('EnumOption const must be a non-empty string.');
        Assert::that($title)->isNonEmptyString('EnumOption title must be a non-empty string.');

        $this->const = $const;
        $this->title = $title;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('const', 'EnumOption data missing "const".');
        $const = $data['const'];
        Assert::that($const)->isString('EnumOption "const" must be a string, {type} given.');

        Assert::that($data)->hasOffset('title', 'EnumOption data missing "title".');
        $title = $data['title'];
        Assert::that($title)->isString('EnumOption "title" must be a string, {type} given.');

        return new self($const, $title);
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
