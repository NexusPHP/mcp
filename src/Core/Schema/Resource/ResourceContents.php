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
use Nexus\Assert\ExpectationFailedException;
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
        public ?MetaObject $meta = null,
    ) {
        Rfc3986UriValidator::validate($uri, 'ResourceContents');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('ResourceContents mimeType must be a non-empty string or null.');

        $this->uri = $uri;
        $this->mimeType = $mimeType;
    }

    /**
     * Discriminates the wire payload by the presence of `text` vs `blob` and
     * dispatches to the matching concrete subclass. Used by consumers like
     * `ReadResourceResult` and `EmbeddedResource` that carry a structurally
     * tagged union. The narrowed return type lets IDEs and PHPStan resolve
     * `text` / `blob` on the result without an explicit `instanceof` check.
     *
     * @param array<string, mixed> $data
     *
     * @throws ExpectationFailedException when neither (or both) discriminators are present
     */
    public static function from(array $data): BlobResourceContents|TextResourceContents
    {
        if (\array_key_exists('text', $data) && \array_key_exists('blob', $data)) {
            throw new ExpectationFailedException('ResourceContents wire data must not have both "text" and "blob".');
        }

        if (\array_key_exists('text', $data)) {
            return TextResourceContents::fromArray($data);
        }

        if (\array_key_exists('blob', $data)) {
            return BlobResourceContents::fromArray($data);
        }

        throw new ExpectationFailedException('ResourceContents wire data must have either "text" or "blob".');
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
