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
 * Schema for a numeric elicitation field.
 *
 * @implements Arrayable<array{
 *   type: 'integer'|'number',
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minimum?: int,
 *   maximum?: int,
 *   default?: int,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#numberschema
 */
final readonly class NumberSchema implements Arrayable, PrimitiveSchemaDefinition
{
    public const string TYPE = 'number';
    public const string TYPE_INTEGER = 'integer';

    /**
     * @var 'integer'|'number'
     */
    public string $type;

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    public function __construct(
        string $type = self::TYPE,
        ?string $title = null,
        ?string $description = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?int $default = null,
    ) {
        Assert::that($type)->isOneOf([self::TYPE, self::TYPE_INTEGER], 'NumberSchema type must be one of "number", "integer".');
        Assert::that($title)->nullOr()->isNonEmptyString('NumberSchema title must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('NumberSchema description must be a non-empty string or null.');

        $this->type = $type;
        $this->title = $title;
        $this->description = $description;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'NumberSchema wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isString('NumberSchema wire "type" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('NumberSchema wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('NumberSchema wire "description" must be a string or null, {type} given.');

        $minimum = $data['minimum'] ?? null;
        Assert::that($minimum)->nullOr()->isInt('NumberSchema wire "minimum" must be an int or null, {type} given.');

        $maximum = $data['maximum'] ?? null;
        Assert::that($maximum)->nullOr()->isInt('NumberSchema wire "maximum" must be an int or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isInt('NumberSchema wire "default" must be an int or null, {type} given.');

        return new self($type, $title, $description, $minimum, $maximum, $default);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['type' => $this->type];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->minimum) {
            $data['minimum'] = $this->minimum;
        }

        if (null !== $this->maximum) {
            $data['maximum'] = $this->maximum;
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
