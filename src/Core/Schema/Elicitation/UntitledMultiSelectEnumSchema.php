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

/**
 * Schema for multiple-selection enumeration without display titles for options.
 *
 * @implements MultiSelectEnumSchema<array{
 *   type: 'array',
 *   items: array{type: 'string', enum: list<non-empty-string>},
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minItems?: int<0, max>,
 *   maxItems?: int<0, max>,
 *   default?: list<string>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#untitledmultiselectenumschema
 */
final readonly class UntitledMultiSelectEnumSchema implements MultiSelectEnumSchema
{
    public const string TYPE = 'array';
    public const string ITEMS_TYPE = 'string';

    /**
     * @param list<non-empty-string> $items       The inner `enum` values
     * @param null|non-empty-string  $title
     * @param null|non-empty-string  $description
     * @param null|int<0, max>       $minItems
     * @param null|int<0, max>       $maxItems
     * @param null|list<string>      $default
     */
    public function __construct(
        public array $items,
        public ?string $title = null,
        public ?string $description = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?array $default = null,
    ) {
        Assert::that($items)
            ->isList('untitled multi-select enum schema "items" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each untitled multi-select enum schema "items" must be a non-empty string.')
        ;
        Assert::that($title)
            ->nullOr()
            ->isNonEmptyString('untitled multi-select enum schema "title" must be a non-empty string or null.')
        ;
        Assert::that($description)
            ->nullOr()
            ->isNonEmptyString('untitled multi-select enum schema "description" must be a non-empty string or null.')
        ;
        Assert::that($minItems)
            ->nullOr()
            ->isNaturalInt('untitled multi-select enum schema "minItems" must be a non-negative integer or null.')
        ;
        Assert::that($maxItems)
            ->nullOr()
            ->isNaturalInt('untitled multi-select enum schema "maxItems" must be a non-negative integer or null.')
        ;

        if (null !== $default) {
            Assert::that($default)
                ->isList('untitled multi-select enum schema "default" must be a list, non-list array given.')
                ->values()->isString('each untitled multi-select enum schema "default" must be a string.')
            ;
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'untitled multi-select enum schema is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'untitled multi-select enum schema "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('items', 'untitled multi-select enum schema is missing the required "items" key.');
        Assert::that($data['items'])
            ->isArray('untitled multi-select enum schema "items" must be an object, {type} given.')
            ->isMap('untitled multi-select enum schema "items" must be a string-keyed object.')
        ;

        $itemsType = $data['items']['type'] ?? null;
        Assert::that($itemsType)->isIdentical(self::ITEMS_TYPE, 'untitled multi-select enum schema "items.type" must be {other}, {value} given.');

        $itemsEnum = $data['items']['enum'] ?? null;
        Assert::that($itemsEnum)
            ->isArray('untitled multi-select enum schema "items.enum" must be a list, {type} given.')
            ->isList('untitled multi-select enum schema "items.enum" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each untitled multi-select enum schema "items.enum" must be a non-empty string, {type} given.')
        ;

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isNonEmptyString('untitled multi-select enum schema "title" must be a non-empty string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isNonEmptyString('untitled multi-select enum schema "description" must be a non-empty string or null, {type} given.');

        $minItems = $data['minItems'] ?? null;
        Assert::that($minItems)->nullOr()->isNaturalInt('untitled multi-select enum schema "minItems" must be a non-negative integer or null, {type} given.');

        $maxItems = $data['maxItems'] ?? null;
        Assert::that($maxItems)->nullOr()->isNaturalInt('untitled multi-select enum schema "maxItems" must be a non-negative integer or null, {type} given.');

        $default = null;

        if (isset($data['default'])) {
            Assert::that($data['default'])
                ->isList('untitled multi-select enum schema "default" must be a list, non-list array given.')
                ->values()->isString('each untitled multi-select enum schema "default" must be a string, {type} given.')
            ;
            $default = $data['default'];
        }

        return new self(
            items: $itemsEnum,
            title: $title,
            description: $description,
            minItems: $minItems,
            maxItems: $maxItems,
            default: $default,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'items' => ['type' => self::ITEMS_TYPE, 'enum' => $this->items],
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->minItems) {
            $data['minItems'] = $this->minItems;
        }

        if (null !== $this->maxItems) {
            $data['maxItems'] = $this->maxItems;
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
