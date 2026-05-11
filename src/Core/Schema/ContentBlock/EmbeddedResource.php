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
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

/**
 * The contents of a resource, embedded into a prompt or tool call result.
 *
 * It is up to the client how best to render embedded resources for the benefit of the LLM and/or the user.
 *
 * @implements Arrayable<array{
 *   resource: template-type<ResourceContents, Arrayable, 'T'>,
 *   type: 'resource',
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#embeddedresource
 */
final readonly class EmbeddedResource implements Arrayable, ContentBlock
{
    public const string TYPE = 'resource';

    public function __construct(
        public BlobResourceContents|TextResourceContents $resource,
        public ?Annotations $annotations = null,
        public ?MetaObject $meta = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'EmbeddedResource wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('EmbeddedResource wire "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('resource', 'EmbeddedResource wire data missing "resource".');
        Assert::that($data['resource'])
            ->isArray('EmbeddedResource wire "resource" must be an object, {type} given.')
            ->isMap('EmbeddedResource wire "resource" must be a string-keyed object.')
        ;
        $resource = ResourceContents::from($data['resource']);

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('EmbeddedResource wire "annotations" must be an object, {type} given.')
                ->isMap('EmbeddedResource wire "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $meta = MetaObject::parseFromWire($data, 'EmbeddedResource');

        return new self($resource, $annotations, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'resource' => $this->resource->toArray(),
            'type' => self::TYPE,
        ];

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations->toArray();
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
