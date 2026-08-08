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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;

/**
 * Binary resource contents. The `blob` payload carries a base64-encoded
 * representation of the binary data.
 *
 * @extends ResourceContents<array{
 *   uri: non-empty-string,
 *   blob: string,
 *   mimeType?: non-empty-string,
 *   _meta?: template-type<PayloadMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#blobresourcecontents
 */
final readonly class BlobResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public string $blob,
        ?string $mimeType = null,
        PayloadMetaObject $meta = new PayloadMetaObject(),
    ) {
        parent::__construct(uri: $uri, mimeType: $mimeType, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'blob resource contents is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('blob resource contents "uri" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('blob', 'blob resource contents is missing the required "blob" key.');
        $blob = $data['blob'];
        Assert::that($blob)->isString('blob resource contents "blob" must be a string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isNonEmptyString('blob resource contents "mimeType" must be a non-empty string or null, {type} given.');

        $meta = new PayloadMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('blob resource contents "_meta" must be an object, {type} given.')
                ->not()->isNonEmptyList('blob resource contents "_meta" must be a string-keyed object.')
            ;
            $meta = PayloadMetaObject::fromArray($data['_meta']);
        }

        return new self(uri: $uri, blob: $blob, mimeType: $mimeType, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'uri' => $this->uri,
            'blob' => $this->blob,
        ];

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }
}
