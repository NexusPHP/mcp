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
use Nexus\Mcp\Core\Schema\MetaObject;
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
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
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
        public Annotations $annotations = new Annotations(),
        public ?float $size = null,
        ?array $icons = null,
        public MetaObject $meta = new MetaObject(),
    ) {
        parent::__construct($name, $title);

        IdentifierNameValidator::validate($name, 'Resource');
        Rfc3986UriValidator::validate($uri, 'Resource');

        Assert::that($description)->nullOr()->isNonEmptyString('Resource description must be a non-empty string or null.');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('Resource mimeType must be a non-empty string or null.');

        if (null !== $icons) {
            Assert::that($icons)->values()->isInstanceOf(Icon::class);
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
        Assert::that($data)->hasOffset('name', 'Resource data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('Resource "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('uri', 'Resource data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('Resource "uri" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('Resource "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('Resource "description" must be a string or null, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('Resource "mimeType" must be a string or null, {type} given.');

        $annotations = new Annotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('Resource "annotations" must be an object, {type} given.')
                ->isMap('Resource "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $size = $data['size'] ?? null;

        if (null !== $size) {
            $size = self::parseNumber($size, 'Resource "size" must be a number or null, {type} given.');
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])
                ->isList('Resource "icons" must be a list, {type} given.')
                ->values()
                ->isArray('Resource icon entry must be an object, {type} given.')
                ->isMap('Resource icon entry must be a string-keyed object.')
            ;
            $icons = array_map(Icon::fromArray(...), $data['icons']);
        }

        $meta = MetaObject::parseFrom($data, 'Resource');

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

        $annotations = $this->annotations->toArray();

        if ([] !== $annotations) {
            $data['annotations'] = $annotations;
        }

        if (null !== $this->size) {
            $data['size'] = $this->size;
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(static fn(Icon $icon): array => $icon->toArray(), $this->icons);
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
