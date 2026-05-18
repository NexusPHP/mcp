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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#blobresourcecontents
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
        Assert::that($data)->hasOffset('uri', 'BlobResourceContents data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('BlobResourceContents "uri" must be a string, {type} given.');

        Assert::that($data)->hasOffset('blob', 'BlobResourceContents data missing "blob".');
        $blob = $data['blob'];
        Assert::that($blob)->isString('BlobResourceContents "blob" must be a string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('BlobResourceContents "mimeType" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('ResourceContents "_meta" must be an object, {type} given.')
                ->isMap('ResourceContents "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($uri, $blob, $mimeType, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'blob' => $this->blob];
    }
}
