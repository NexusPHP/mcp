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

namespace Nexus\Mcp\Core\Schema\ContentBlock;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;

/**
 * A resource that the server is capable of reading, included in a prompt or tool call result.
 *
 * Note: resource links returned by tools are not guaranteed to appear in the results of `resources/list` requests.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   type: 'resource_link',
 *   uri: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   mimeType?: non-empty-string,
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   size?: int,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<PayloadMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#resourcelink
 */
final readonly class ResourceLink extends BaseMetadata implements Arrayable, ContentBlock, Icons
{
    public const string TYPE = 'resource_link';

    /**
     * @param non-empty-string      $uri
     * @param null|non-empty-string $description
     * @param null|non-empty-string $mimeType
     * @param null|list<Icon>       $icons
     */
    public function __construct(
        string $name,
        public string $uri,
        ?string $title = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public Annotations $annotations = new Annotations(),
        public ?int $size = null,
        public ?array $icons = null,
        public PayloadMetaObject $meta = new PayloadMetaObject(),
    ) {
        parent::__construct(name: $name, title: $title);

        Rfc3986UriValidator::validate($uri, 'resource link "uri"');

        Assert::that($description)
            ->nullOr()
            ->isNonEmptyString('resource link "description" must be a non-empty string or null.')
        ;
        Assert::that($mimeType)
            ->nullOr()
            ->isNonEmptyString('resource link "mimeType" must be a non-empty string or null.')
        ;

        if (null !== $icons) {
            Assert::that($icons)->values()->isInstanceOf(Icon::class);
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'resource link is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'resource link "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('name', 'resource link is missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isNonEmptyString('resource link "name" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('uri', 'resource link is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('resource link "uri" must be a non-empty string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isNonEmptyString('resource link "title" must be a non-empty string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isNonEmptyString('resource link "description" must be a non-empty string or null, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isNonEmptyString('resource link "mimeType" must be a non-empty string or null, {type} given.');

        $annotations = new Annotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('resource link "annotations" must be an object, {type} given.')
                ->isMap('resource link "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $size = $data['size'] ?? null;
        Assert::that($size)->nullOr()->isInt('resource link "size" must be an integer or null, {type} given.');

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])
                ->isList('resource link "icons" must be a list, {type} given.')
                ->values()
                ->isArray('each resource link "icon" must be an object, {type} given.')
                ->isMap('each resource link "icon" must be a string-keyed object.')
            ;
            $icons = array_map(Icon::fromArray(...), $data['icons']);
        }

        $meta = new PayloadMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('resource link "_meta" must be an object, {type} given.')
                ->isMap('resource link "_meta" must be a string-keyed object.')
            ;
            $meta = PayloadMetaObject::fromArray($data['_meta']);
        }

        return new self(
            name: $name,
            uri: $uri,
            title: $title,
            description: $description,
            mimeType: $mimeType,
            annotations: $annotations,
            size: $size,
            icons: $icons,
            meta: $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => self::TYPE,
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
