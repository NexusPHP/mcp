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

namespace Nexus\Mcp\Extension\Apps\Client\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when a `ui://` read returns a content item whose mime type is not
 * one of the accepted UI types.
 */
final class InvalidUiResourceContentsException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * @param non-empty-string       $uri
     * @param list<non-empty-string> $acceptedMimeTypes
     */
    public function __construct(string $uri, ?string $mimeType, array $acceptedMimeTypes)
    {
        parent::__construct(\sprintf(
            'UI resource "%s" returned contents of mime type "%s", expected one of "%s".',
            $uri,
            $mimeType ?? '',
            implode('", "', $acceptedMimeTypes),
        ));
    }
}
