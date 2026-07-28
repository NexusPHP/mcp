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
use Nexus\Mcp\Core\JsonRpc\ResourceContentsDispatcher;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
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
 *   _meta?: template-type<PayloadMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#embeddedresource
 */
final readonly class EmbeddedResource implements Arrayable, ContentBlock
{
    public const string TYPE = 'resource';

    public function __construct(
        public BlobResourceContents|TextResourceContents $resource,
        public Annotations $annotations = new Annotations(),
        public PayloadMetaObject $meta = new PayloadMetaObject(),
    ) {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'embedded resource is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'embedded resource "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('resource', 'embedded resource is missing the required "resource" key.');
        Assert::that($data['resource'])
            ->isArray('embedded resource "resource" must be an object, {type} given.')
            ->isMap('embedded resource "resource" must be a string-keyed object.')
        ;
        $resource = ResourceContentsDispatcher::fromArray($data['resource'], 'EmbeddedResource resource');

        $annotations = new Annotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('embedded resource "annotations" must be an object, {type} given.')
                ->isMap('embedded resource "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $meta = new PayloadMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('embedded resource "_meta" must be an object, {type} given.')
                ->isMap('embedded resource "_meta" must be a string-keyed object.')
            ;
            $meta = PayloadMetaObject::fromArray($data['_meta']);
        }

        return new self(resource: $resource, annotations: $annotations, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'resource' => $this->resource->toArray(),
            'type' => self::TYPE,
        ];

        $annotations = $this->annotations->toArray();

        if ([] !== $annotations) {
            $data['annotations'] = $annotations;
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
