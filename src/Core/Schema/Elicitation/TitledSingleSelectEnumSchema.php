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
 * Schema for single-selection enumeration with display titles for each option.
 *
 * @implements Arrayable<array{
 *   type: 'string',
 *   oneOf: list<template-type<EnumOption, Arrayable, 'T'>>,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   default?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#titledsingleselectenumschema
 */
final readonly class TitledSingleSelectEnumSchema implements Arrayable, SingleSelectEnumSchema
{
    public const string TYPE = 'string';

    /**
     * @var list<EnumOption>
     */
    public array $oneOf;

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @param list<EnumOption> $oneOf
     */
    public function __construct(
        array $oneOf,
        ?string $title = null,
        ?string $description = null,
        public ?string $default = null,
    ) {
        Assert::that($oneOf)
            ->isList('TitledSingleSelectEnumSchema oneOf must be a list, got non-list array.')
            ->values()->isInstanceOf(EnumOption::class)
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('TitledSingleSelectEnumSchema title must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('TitledSingleSelectEnumSchema description must be a non-empty string or null.');

        $this->oneOf = $oneOf;
        $this->title = $title;
        $this->description = $description;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'TitledSingleSelectEnumSchema wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('TitledSingleSelectEnumSchema wire "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('oneOf', 'TitledSingleSelectEnumSchema wire data missing "oneOf".');
        Assert::that($data['oneOf'])
            ->isList('TitledSingleSelectEnumSchema wire "oneOf" must be a list, got non-list array.')
            ->values()
            ->isArray('TitledSingleSelectEnumSchema wire oneOf entry must be an object, {type} given.')
            ->isMap('TitledSingleSelectEnumSchema wire oneOf entry must be a string-keyed object.')
        ;
        $oneOf = array_map(EnumOption::fromArray(...), $data['oneOf']);

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('TitledSingleSelectEnumSchema wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('TitledSingleSelectEnumSchema wire "description" must be a string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('TitledSingleSelectEnumSchema wire "default" must be a string or null, {type} given.');

        return new self($oneOf, $title, $description, $default);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'oneOf' => array_map(static fn(EnumOption $o): array => $o->toArray(), $this->oneOf),
        ];

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
