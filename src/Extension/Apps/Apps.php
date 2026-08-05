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

namespace Nexus\Mcp\Extension\Apps;

/**
 * Protocol vocabulary of the MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865).
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
final readonly class Apps
{
    /**
     * The extension identifier used in capability negotiation.
     */
    public const string IDENTIFIER = 'io.modelcontextprotocol/ui';

    /**
     * The mime type every UI resource carries.
     */
    public const string MIME_TYPE = 'text/html;profile=mcp-app';

    /**
     * The URI scheme every UI resource uses.
     */
    public const string URI_PREFIX = 'ui://';

    /**
     * The `_meta` key carrying UI metadata on tools, resources, and contents.
     */
    public const string META_KEY = 'ui';

    /**
     * Deprecated flat form of `_meta.ui.resourceUri`. The SDK reads it as a
     * fallback and never emits it.
     */
    public const string DEPRECATED_RESOURCE_URI_KEY = 'ui/resourceUri';
}
