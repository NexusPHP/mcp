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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Validation\Sep986NameValidator;

/**
 * A prompt or prompt template that the server offers.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   arguments?: list<template-type<PromptArgument, Arrayable, 'T'>>,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<Meta, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#prompt
 */
final readonly class Prompt extends BaseMetadata implements Arrayable, Icons
{
    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var null|list<PromptArgument>
     */
    public ?array $arguments;

    /**
     * @var null|list<Icon>
     */
    public ?array $icons;

    /**
     * @param null|list<PromptArgument> $arguments
     * @param null|list<Icon>           $icons
     */
    public function __construct(
        string $name,
        ?string $title = null,
        ?string $description = null,
        ?array $arguments = null,
        ?array $icons = null,
        public ?Meta $meta = null,
    ) {
        parent::__construct($name, $title);

        Sep986NameValidator::validate($name, 'Prompt');
        Assert::that($description)->nullOr()->isNonEmptyString('Prompt description must be a non-empty string or null.');

        if (null !== $arguments) {
            foreach ($arguments as $argument) {
                Assert::that($argument)->isInstanceOf(PromptArgument::class);
            }
        }

        if (null !== $icons) {
            foreach ($icons as $icon) {
                Assert::that($icon)->isInstanceOf(Icon::class);
            }
        }

        $this->description = $description;
        $this->arguments = $arguments;
        $this->icons = $icons;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'Prompt wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('Prompt wire "name" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('Prompt wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('Prompt wire "description" must be a string or null, {type} given.');

        $arguments = null;

        if (isset($data['arguments'])) {
            Assert::that($data['arguments'])->isArray('Prompt wire "arguments" must be an array, {type} given.');

            $arguments = [];

            foreach ($data['arguments'] as $argumentData) {
                Assert::that($argumentData)
                    ->isArray('Prompt wire argument entry must be an object, {type} given.')
                    ->isMap('Prompt wire argument entry must be a string-keyed object.')
                ;
                $arguments[] = PromptArgument::fromArray($argumentData);
            }
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])->isArray('Prompt wire "icons" must be an array, {type} given.');

            $icons = [];

            foreach ($data['icons'] as $iconData) {
                Assert::that($iconData)
                    ->isArray('Prompt wire icon entry must be an object, {type} given.')
                    ->isMap('Prompt wire icon entry must be a string-keyed object.')
                ;
                $icons[] = Icon::fromArray($iconData);
            }
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Prompt "_meta" must be an object, {type} given.')
                ->isMap('Prompt "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($name, $title, $description, $arguments, $icons, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->arguments) {
            $data['arguments'] = array_map(
                static fn(PromptArgument $argument): array => $argument->toArray(),
                $this->arguments,
            );
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(
                static fn(Icon $icon): array => $icon->toArray(),
                $this->icons,
            );
        }

        if (null !== $this->meta) {
            $meta = $this->meta->toArray();

            if ([] !== $meta) {
                $data['_meta'] = $meta;
            }
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
