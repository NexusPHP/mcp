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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;

/**
 * Common params for resource-related requests.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends RequestParams<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
abstract readonly class ResourceRequestParams extends RequestParams
{
    private const int MAX_URI_BYTES = 8_192;

    /**
     * @param non-empty-string $uri
     */
    public function __construct(
        public string $uri,
        RequestMetaObject $meta,
    ) {
        Rfc3986UriValidator::validate($uri, '"params.uri"');
        Assert::that($uri)->hasMaxLength(
            self::MAX_URI_BYTES,
            \sprintf('"params.uri" must not exceed %d bytes.', self::MAX_URI_BYTES),
        );

        parent::__construct(meta: $meta);
    }
}
