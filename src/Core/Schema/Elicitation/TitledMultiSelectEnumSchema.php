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
 * Schema for multiple-selection enumeration with display titles for each option.
 *
 * @implements Arrayable<array{
 *   type: 'array',
 *   items: array{anyOf: list<template-type<EnumOption, Arrayable, 'T'>>},
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minItems?: int<0, max>,
 *   maxItems?: int<0, max>,
 *   default?: list<string>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#titledmultiselectenumschema
 */
final readonly class TitledMultiSelectEnumSchema implements Arrayable, MultiSelectEnumSchema
{
    public const string TYPE = 'array';

    /**
     * @var list<EnumOption>
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
     * @param list<EnumOption>  $items   the inner `anyOf` list of `{const, title}` pairs
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
            ->isList('TitledMultiSelectEnumSchema items must be a list, got non-list array.')
            ->values()->isInstanceOf(EnumOption::class)
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('TitledMultiSelectEnumSchema title must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('TitledMultiSelectEnumSchema description must be a non-empty string or null.');
        Assert::that($minItems)->nullOr()->isNaturalInt('TitledMultiSelectEnumSchema minItems must be a non-negative integer or null.');
        Assert::that($maxItems)->nullOr()->isNaturalInt('TitledMultiSelectEnumSchema maxItems must be a non-negative integer or null.');

        if (null !== $default) {
            Assert::that($default)
                ->isList('TitledMultiSelectEnumSchema default must be a list, got non-list array.')
                ->values()->isString('TitledMultiSelectEnumSchema default entry must be a string.')
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
        Assert::that($data)->hasOffset('type', 'TitledMultiSelectEnumSchema data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('TitledMultiSelectEnumSchema "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('items', 'TitledMultiSelectEnumSchema data missing "items".');
        Assert::that($data['items'])
            ->isArray('TitledMultiSelectEnumSchema "items" must be an object, {type} given.')
            ->isMap('TitledMultiSelectEnumSchema "items" must be a string-keyed object.')
        ;

        $anyOf = $data['items']['anyOf'] ?? null;
        Assert::that($anyOf)
            ->isArray('TitledMultiSelectEnumSchema items.anyOf must be a list, {type} given.')
            ->isList('TitledMultiSelectEnumSchema items.anyOf must be a list, got non-list array.')
            ->values()
            ->isArray('TitledMultiSelectEnumSchema items.anyOf entry must be an object, {type} given.')
            ->isMap('TitledMultiSelectEnumSchema items.anyOf entry must be a string-keyed object.')
        ;
        $items = array_map(EnumOption::fromArray(...), $anyOf);

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('TitledMultiSelectEnumSchema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('TitledMultiSelectEnumSchema "description" must be a string or null, {type} given.');

        $minItems = $data['minItems'] ?? null;
        Assert::that($minItems)->nullOr()->isInt('TitledMultiSelectEnumSchema "minItems" must be an int or null, {type} given.');

        $maxItems = $data['maxItems'] ?? null;
        Assert::that($maxItems)->nullOr()->isInt('TitledMultiSelectEnumSchema "maxItems" must be an int or null, {type} given.');

        $default = null;

        if (isset($data['default'])) {
            Assert::that($data['default'])
                ->isList('TitledMultiSelectEnumSchema "default" must be a list, got non-list array.')
                ->values()->isString('TitledMultiSelectEnumSchema default entry must be a string, {type} given.')
            ;
            $default = $data['default'];
        }

        return new self($items, $title, $description, $minItems, $maxItems, $default);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'items' => [
                'anyOf' => array_map(static fn(EnumOption $o): array => $o->toArray(), $this->items),
            ],
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
