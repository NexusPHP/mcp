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

namespace Nexus\Mcp\Core\Schema\Prompt;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * A prompt or prompt template that the server offers.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   arguments?: list<template-type<PromptArgument, Arrayable, 'T'>>,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<PayloadMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#prompt
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
        public PayloadMetaObject $meta = new PayloadMetaObject(),
    ) {
        parent::__construct(name: $name, title: $title);

        IdentifierNameValidator::validate($name, 'prompt "name"');
        Assert::that($description)->nullOr()->isNonEmptyString('prompt "description" must be a non-empty string or null.');

        if (null !== $arguments) {
            Assert::that($arguments)->values()->isInstanceOf(PromptArgument::class);
        }

        if (null !== $icons) {
            Assert::that($icons)->values()->isInstanceOf(Icon::class);
        }

        $this->description = $description;
        $this->arguments = $arguments;
        $this->icons = $icons;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'prompt is missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isNonEmptyString('prompt "name" must be a non-empty string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isNonEmptyString('prompt "title" must be a non-empty string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isNonEmptyString('prompt "description" must be a non-empty string or null, {type} given.');

        $arguments = null;

        if (isset($data['arguments'])) {
            Assert::that($data['arguments'])
                ->isList('prompt "arguments" must be a list, {type} given.')
                ->values()
                ->isArray('each prompt "arguments" must be an object, {type} given.')
                ->isMap('each prompt "arguments" must be a string-keyed object.')
            ;
            $arguments = array_map(PromptArgument::fromArray(...), $data['arguments']);
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])
                ->isList('prompt "icons" must be a list, {type} given.')
                ->values()
                ->isArray('each prompt "icons" must be an object, {type} given.')
                ->isMap('each prompt "icons" must be a string-keyed object.')
            ;
            $icons = array_map(Icon::fromArray(...), $data['icons']);
        }

        $meta = new PayloadMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('prompt "_meta" must be an object, {type} given.')
                ->isMap('prompt "_meta" must be a string-keyed object.')
            ;
            $meta = PayloadMetaObject::fromArray($data['_meta']);
        }

        return new self(
            name: $name,
            title: $title,
            description: $description,
            arguments: $arguments,
            icons: $icons,
            meta: $meta,
        );
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

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
