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
 * Schema for a boolean elicitation field.
 *
 * @implements Arrayable<array{
 *   type: 'boolean',
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   default?: bool,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#booleanschema
 */
final readonly class BooleanSchema implements Arrayable, PrimitiveSchemaDefinition
{
    public const string TYPE = 'boolean';

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    public function __construct(
        ?string $title = null,
        ?string $description = null,
        public ?bool $default = null,
    ) {
        Assert::that($title)->nullOr()->isNonEmptyString('boolean schema "title" must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('boolean schema "description" must be a non-empty string or null.');

        $this->title = $title;
        $this->description = $description;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'boolean schema missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'boolean schema "type" must be {other}, {value} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('boolean schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('boolean schema "description" must be a string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isBool('boolean schema "default" must be a bool or null, {type} given.');

        return new self($title, $description, $default);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['type' => self::TYPE];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
