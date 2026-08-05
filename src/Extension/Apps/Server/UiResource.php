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

namespace Nexus\Mcp\Extension\Apps\Server;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;

/**
 * A UI resource declaration: composes a `Resource` bound to the `ui://`
 * scheme and the `text/html;profile=mcp-app` mime type, with the `_meta.ui`
 * slot carrying the given metadata. Pass `$resource` to
 * `ServerBuilder::addResource()` with a reader returning the HTML document,
 * and reuse `$resource->meta` on the read contents so both positions carry
 * the same metadata.
 */
final readonly class UiResource
{
    public Resource $resource;

    /**
     * @param non-empty-string      $name
     * @param non-empty-string      $uri
     * @param null|non-empty-string $title
     * @param null|non-empty-string $description
     * @param null|list<Icon>       $icons
     */
    public function __construct(
        string $name,
        string $uri,
        ?string $title = null,
        ?string $description = null,
        public ?UiResourceMeta $uiMeta = null,
        Annotations $annotations = new Annotations(),
        ?int $size = null,
        ?array $icons = null,
    ) {
        Assert::that($uri)->startsWith(Apps::URI_PREFIX, \sprintf('UI resource "uri" must start with "%s".', Apps::URI_PREFIX));

        $extras = [];

        if (null !== $uiMeta) {
            $uiMetaData = $uiMeta->toArray();

            if ([] !== $uiMetaData) {
                $extras[Apps::META_KEY] = $uiMetaData;
            }
        }

        $this->resource = new Resource(
            name: $name,
            uri: $uri,
            title: $title,
            description: $description,
            mimeType: Apps::MIME_TYPE,
            annotations: $annotations,
            size: $size,
            icons: $icons,
            meta: new PayloadMetaObject(extras: $extras),
        );
    }
}
