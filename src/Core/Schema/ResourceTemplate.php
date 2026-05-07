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
use Nexus\Mcp\Core\Validation\Rfc6570UriTemplateValidator;
use Nexus\Mcp\Core\Validation\Sep986NameValidator;

/**
 * A template description for resources available on the server.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   uriTemplate: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   mimeType?: non-empty-string,
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<Meta, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourcetemplate
 */
final readonly class ResourceTemplate extends BaseMetadata implements Arrayable, Icons
{
    /**
     * @var non-empty-string
     */
    public string $uriTemplate;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var null|non-empty-string
     */
    public ?string $mimeType;

    /**
     * @var null|list<Icon>
     */
    public ?array $icons;

    /**
     * @param null|list<Icon> $icons
     */
    public function __construct(
        string $name,
        string $uriTemplate,
        ?string $title = null,
        ?string $description = null,
        ?string $mimeType = null,
        public ?Annotations $annotations = null,
        ?array $icons = null,
        public ?Meta $meta = null,
    ) {
        parent::__construct($name, $title);

        Sep986NameValidator::validate($name, 'ResourceTemplate');
        Rfc6570UriTemplateValidator::validate($uriTemplate, 'ResourceTemplate');

        Assert::that($description)->nullOr()->isNonEmptyString('ResourceTemplate description must be a non-empty string or null.');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('ResourceTemplate mimeType must be a non-empty string or null.');

        if (null !== $icons) {
            foreach ($icons as $icon) {
                Assert::that($icon)->isInstanceOf(Icon::class);
            }
        }

        $this->uriTemplate = $uriTemplate;
        $this->description = $description;
        $this->mimeType = $mimeType;
        $this->icons = $icons;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'ResourceTemplate wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('ResourceTemplate wire "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('uriTemplate', 'ResourceTemplate wire data missing "uriTemplate".');
        $uriTemplate = $data['uriTemplate'];
        Assert::that($uriTemplate)->isString('ResourceTemplate wire "uriTemplate" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('ResourceTemplate wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('ResourceTemplate wire "description" must be a string or null, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('ResourceTemplate wire "mimeType" must be a string or null, {type} given.');

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('ResourceTemplate wire "annotations" must be an object, {type} given.')
                ->isMap('ResourceTemplate wire "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])->isArray('ResourceTemplate wire "icons" must be an array, {type} given.');

            $icons = [];

            foreach ($data['icons'] as $iconData) {
                Assert::that($iconData)
                    ->isArray('ResourceTemplate wire icon entry must be an object, {type} given.')
                    ->isMap('ResourceTemplate wire icon entry must be a string-keyed object.')
                ;
                $icons[] = Icon::fromArray($iconData);
            }
        }

        $meta = Meta::parseFromWire($data, 'ResourceTemplate');

        return new self($name, $uriTemplate, $title, $description, $mimeType, $annotations, $icons, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'uriTemplate' => $this->uriTemplate,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations->toArray();
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(static fn(Icon $icon): array => $icon->toArray(), $this->icons);
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
