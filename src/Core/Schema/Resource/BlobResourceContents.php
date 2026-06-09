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

/**
 * Binary resource contents. The `blob` payload carries a base64-encoded
 * representation of the binary data.
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#blobresourcecontents
 */
final readonly class BlobResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public string $blob,
        ?string $mimeType = null,
        MetaObject $meta = new MetaObject(),
    ) {
        parent::__construct($uri, $mimeType, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'blob resource contents missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('blob resource contents "uri" must be a string, {type} given.');

        Assert::that($data)->hasOffset('blob', 'blob resource contents missing the required "blob" key.');
        $blob = $data['blob'];
        Assert::that($blob)->isString('blob resource contents "blob" must be a string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('blob resource contents "mimeType" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('blob resource contents "_meta" must be an object, {type} given.')
                ->isMap('blob resource contents "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(uri: $uri, blob: $blob, mimeType: $mimeType, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'blob' => $this->blob];
    }
}
