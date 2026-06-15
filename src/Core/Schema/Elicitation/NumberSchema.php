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
use Nexus\Mcp\Core\Schema\ParsesNumber;

/**
 * Schema for a numeric elicitation field.
 *
 * @implements PrimitiveSchemaDefinition<array{
 *   type: 'integer'|'number',
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minimum?: float,
 *   maximum?: float,
 *   default?: float,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#numberschema
 */
final readonly class NumberSchema implements PrimitiveSchemaDefinition
{
    use ParsesNumber;

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
        public ?float $minimum = null,
        public ?float $maximum = null,
        public ?float $default = null,
    ) {
        Assert::that($type)->isOneOf([self::TYPE, self::TYPE_INTEGER], 'number schema "type" must be one of {choices}.');
        Assert::that($title)
            ->nullOr()
            ->isNonEmptyString('number schema "title" must be a non-empty string or null.')
        ;
        Assert::that($description)
            ->nullOr()
            ->isNonEmptyString('number schema "description" must be a non-empty string or null.')
        ;

        $this->type = $type;
        $this->title = $title;
        $this->description = $description;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'number schema is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isString('number schema "type" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('number schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('number schema "description" must be a string or null, {type} given.');

        $minimum = $data['minimum'] ?? null;

        if (null !== $minimum) {
            $minimum = self::parseNumber($minimum, 'number schema "minimum" must be a number or null, {type} given.');
        }

        $maximum = $data['maximum'] ?? null;

        if (null !== $maximum) {
            $maximum = self::parseNumber($maximum, 'number schema "maximum" must be a number or null, {type} given.');
        }

        $default = $data['default'] ?? null;

        if (null !== $default) {
            $default = self::parseNumber($default, 'number schema "default" must be a number or null, {type} given.');
        }

        return new self(
            type: $type,
            title: $title,
            description: $description,
            minimum: $minimum,
            maximum: $maximum,
            default: $default,
        );
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
