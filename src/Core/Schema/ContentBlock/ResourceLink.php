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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\ParsesNumber;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;
use Nexus\Mcp\Core\Validation\Sep986NameValidator;

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
 *   size?: float,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<Meta, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourcelink
 */
final readonly class ResourceLink extends BaseMetadata implements Arrayable, ContentBlock, Icons
{
    use ParsesNumber;

    public const string TYPE = 'resource_link';

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

        Sep986NameValidator::validate($name, 'ResourceLink');
        Rfc3986UriValidator::validate($uri, 'ResourceLink');

        Assert::that($description)->nullOr()->isNonEmptyString('ResourceLink description must be a non-empty string or null.');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('ResourceLink mimeType must be a non-empty string or null.');

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
        Assert::that($data)->hasOffset('type', 'ResourceLink wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('ResourceLink wire "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('name', 'ResourceLink wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('ResourceLink wire "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('uri', 'ResourceLink wire data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('ResourceLink wire "uri" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('ResourceLink wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('ResourceLink wire "description" must be a string or null, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('ResourceLink wire "mimeType" must be a string or null, {type} given.');

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('ResourceLink wire "annotations" must be an object, {type} given.')
                ->isMap('ResourceLink wire "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $size = $data['size'] ?? null;

        if (null !== $size) {
            $size = self::parseNumber($size, 'ResourceLink wire "size" must be a number or null, {type} given.');
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])->isArray('ResourceLink wire "icons" must be an array, {type} given.');

            $icons = [];

            foreach ($data['icons'] as $iconData) {
                Assert::that($iconData)
                    ->isArray('ResourceLink wire icon entry must be an object, {type} given.')
                    ->isMap('ResourceLink wire icon entry must be a string-keyed object.')
                ;
                $icons[] = Icon::fromArray($iconData);
            }
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('ResourceLink "_meta" must be an object, {type} given.')
                ->isMap('ResourceLink "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($name, $uri, $title, $description, $mimeType, $annotations, $size, $icons, $meta);
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
