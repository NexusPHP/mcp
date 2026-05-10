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

namespace Nexus\Mcp\Core\Schema\Resource;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\ParsesNumber;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;

/**
 * A known resource that the server is capable of reading.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   uri: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   mimeType?: non-empty-string,
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   size?: float,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<Meta, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resource
 */
final readonly class Resource extends BaseMetadata implements Arrayable, Icons
{
    use ParsesNumber;

    /**
     * @var non-empty-string
     */
    public string $uri;

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
        string $uri,
        ?string $title = null,
        ?string $description = null,
        ?string $mimeType = null,
        public ?Annotations $annotations = null,
        public ?float $size = null,
        ?array $icons = null,
        public ?Meta $meta = null,
    ) {
        parent::__construct($name, $title);

        IdentifierNameValidator::validate($name, 'Resource');
        Rfc3986UriValidator::validate($uri, 'Resource');

        Assert::that($description)->nullOr()->isNonEmptyString('Resource description must be a non-empty string or null.');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('Resource mimeType must be a non-empty string or null.');

        if (null !== $icons) {
            foreach ($icons as $icon) {
                Assert::that($icon)->isInstanceOf(Icon::class);
            }
        }

        $this->uri = $uri;
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
        Assert::that($data)->hasOffset('name', 'Resource wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('Resource wire "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('uri', 'Resource wire data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('Resource wire "uri" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('Resource wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('Resource wire "description" must be a string or null, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('Resource wire "mimeType" must be a string or null, {type} given.');

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('Resource wire "annotations" must be an object, {type} given.')
                ->isMap('Resource wire "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $size = $data['size'] ?? null;

        if (null !== $size) {
            $size = self::parseNumber($size, 'Resource wire "size" must be a number or null, {type} given.');
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])->isArray('Resource wire "icons" must be an array, {type} given.');

            $icons = [];

            foreach ($data['icons'] as $iconData) {
                Assert::that($iconData)
                    ->isArray('Resource wire icon entry must be an object, {type} given.')
                    ->isMap('Resource wire icon entry must be a string-keyed object.')
                ;
                $icons[] = Icon::fromArray($iconData);
            }
        }

        $meta = Meta::parseFromWire($data, 'Resource');

        return new self($name, $uri, $title, $description, $mimeType, $annotations, $size, $icons, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'uri' => $this->uri,
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

        if (null !== $this->size) {
            $data['size'] = $this->size;
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
