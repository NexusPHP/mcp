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
 * Schema for multiple-selection enumeration without display titles for options.
 *
 * @implements Arrayable<array{
 *   type: 'array',
 *   items: array{type: 'string', enum: list<non-empty-string>},
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minItems?: int<0, max>,
 *   maxItems?: int<0, max>,
 *   default?: list<string>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#untitledmultiselectenumschema
 */
final readonly class UntitledMultiSelectEnumSchema implements Arrayable, MultiSelectEnumSchema
{
    public const string TYPE = 'array';
    public const string ITEMS_TYPE = 'string';

    /**
     * @var list<non-empty-string>
     */
    public array $items;

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var null|list<string>
     */
    public ?array $default;

    /**
     * @param list<string>      $items   the inner `enum` values
     * @param null|list<string> $default
     */
    public function __construct(
        array $items,
        ?string $title = null,
        ?string $description = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        ?array $default = null,
    ) {
        Assert::that($items)
            ->isList('UntitledMultiSelectEnumSchema items must be a list, got non-list array.')
            ->values()->isNonEmptyString('UntitledMultiSelectEnumSchema items entry must be a non-empty string.')
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('UntitledMultiSelectEnumSchema title must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('UntitledMultiSelectEnumSchema description must be a non-empty string or null.');
        Assert::that($minItems)->nullOr()->isNaturalInt('UntitledMultiSelectEnumSchema minItems must be a non-negative integer or null.');
        Assert::that($maxItems)->nullOr()->isNaturalInt('UntitledMultiSelectEnumSchema maxItems must be a non-negative integer or null.');

        if (null !== $default) {
            Assert::that($default)
                ->isList('UntitledMultiSelectEnumSchema default must be a list, got non-list array.')
                ->values()->isString('UntitledMultiSelectEnumSchema default entry must be a string.')
            ;
        }

        $this->items = $items;
        $this->title = $title;
        $this->description = $description;
        $this->default = $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'UntitledMultiSelectEnumSchema data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('UntitledMultiSelectEnumSchema "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('items', 'UntitledMultiSelectEnumSchema data missing "items".');
        Assert::that($data['items'])
            ->isArray('UntitledMultiSelectEnumSchema "items" must be an object, {type} given.')
            ->isMap('UntitledMultiSelectEnumSchema "items" must be a string-keyed object.')
        ;

        $itemsType = $data['items']['type'] ?? null;
        Assert::that($itemsType)->isIdentical(self::ITEMS_TYPE, \sprintf('UntitledMultiSelectEnumSchema items.type must be "%s", {value} given.', self::ITEMS_TYPE));

        $itemsEnum = $data['items']['enum'] ?? null;
        Assert::that($itemsEnum)
            ->isArray('UntitledMultiSelectEnumSchema items.enum must be a list, {type} given.')
            ->isList('UntitledMultiSelectEnumSchema items.enum must be a list, got non-list array.')
            ->values()->isString('UntitledMultiSelectEnumSchema items.enum entry must be a string, {type} given.')
        ;

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('UntitledMultiSelectEnumSchema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('UntitledMultiSelectEnumSchema "description" must be a string or null, {type} given.');

        $minItems = $data['minItems'] ?? null;
        Assert::that($minItems)->nullOr()->isInt('UntitledMultiSelectEnumSchema "minItems" must be an int or null, {type} given.');

        $maxItems = $data['maxItems'] ?? null;
        Assert::that($maxItems)->nullOr()->isInt('UntitledMultiSelectEnumSchema "maxItems" must be an int or null, {type} given.');

        $default = null;

        if (isset($data['default'])) {
            Assert::that($data['default'])
                ->isList('UntitledMultiSelectEnumSchema "default" must be a list, got non-list array.')
                ->values()->isString('UntitledMultiSelectEnumSchema default entry must be a string, {type} given.')
            ;
            $default = $data['default'];
        }

        return new self($itemsEnum, $title, $description, $minItems, $maxItems, $default);
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
