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
 * @see https://modelcontextprotocol.io/specification/draft/schema#titledmultiselectenumschema
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
     * @param list<EnumOption>  $items   The inner `anyOf` list of `{const, title}` pairs
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
            ->isList('titled multi-select enum schema "items" must be a list, non-list array given.')
            ->values()->isInstanceOf(EnumOption::class, 'each titled multi-select enum schema "items" must be an enum option, {type} given.')
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('titled multi-select enum schema "title" must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('titled multi-select enum schema "description" must be a non-empty string or null.');
        Assert::that($minItems)->nullOr()->isNaturalInt('titled multi-select enum schema "minItems" must be a non-negative integer or null.');
        Assert::that($maxItems)->nullOr()->isNaturalInt('titled multi-select enum schema "maxItems" must be a non-negative integer or null.');

        if (null !== $default) {
            Assert::that($default)
                ->isList('titled multi-select enum schema "default" must be a list, non-list array given.')
                ->values()->isString('each titled multi-select enum schema "default" must be a string.')
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
        Assert::that($data)->hasOffset('type', 'titled multi-select enum schema missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'titled multi-select enum schema "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('items', 'titled multi-select enum schema missing the required "items" key.');
        Assert::that($data['items'])
            ->isArray('titled multi-select enum schema "items" must be an object, {type} given.')
            ->isMap('titled multi-select enum schema "items" must be a string-keyed object.')
        ;

        $anyOf = $data['items']['anyOf'] ?? null;
        Assert::that($anyOf)
            ->isArray('titled multi-select enum schema "items.anyOf" must be a list, {type} given.')
            ->isList('titled multi-select enum schema "items.anyOf" must be a list, non-list array given.')
            ->values()
            ->isArray('each titled multi-select enum schema "items.anyOf" must be an object, {type} given.')
            ->isMap('each titled multi-select enum schema "items.anyOf" must be a string-keyed object.')
        ;
        $items = array_map(EnumOption::fromArray(...), $anyOf);

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('titled multi-select enum schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('titled multi-select enum schema "description" must be a string or null, {type} given.');

        $minItems = $data['minItems'] ?? null;
        Assert::that($minItems)->nullOr()->isInt('titled multi-select enum schema "minItems" must be an int or null, {type} given.');

        $maxItems = $data['maxItems'] ?? null;
        Assert::that($maxItems)->nullOr()->isInt('titled multi-select enum schema "maxItems" must be an int or null, {type} given.');

        $default = null;

        if (isset($data['default'])) {
            Assert::that($data['default'])
                ->isList('titled multi-select enum schema "default" must be a list, non-list array given.')
                ->values()->isString('each titled multi-select enum schema "default" must be a string, {type} given.')
            ;
            $default = $data['default'];
        }

        return new self(
            items: $items,
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
