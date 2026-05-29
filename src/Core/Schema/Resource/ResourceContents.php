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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;

/**
 * The contents of a specific resource or sub-resource.
 *
 * @implements Arrayable<array{
 *   uri: non-empty-string,
 *   mimeType?: non-empty-string,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 *   ...<string, mixed>
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
abstract readonly class ResourceContents implements Arrayable
{
    /**
     * @var non-empty-string
     */
    public string $uri;

    /**
     * @var null|non-empty-string
     */
    public ?string $mimeType;

    public function __construct(
        string $uri,
        ?string $mimeType = null,
        public MetaObject $meta = new MetaObject(),
    ) {
        Rfc3986UriValidator::validate($uri, 'resource contents "uri"');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('resource contents "mimeType" must be a non-empty string or null.');

        $this->uri = $uri;
        $this->mimeType = $mimeType;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the shared fields. Subclasses override to merge their own
     * payload field alongside the slice returned here.
     */
    #[\Override]
    public function toArray(): array
    {
        $data = ['uri' => $this->uri];

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
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
