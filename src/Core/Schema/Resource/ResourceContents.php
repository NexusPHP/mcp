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
 * @template T of array<string, mixed> = array<string, mixed>
 *
 * @implements Arrayable<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
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

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
