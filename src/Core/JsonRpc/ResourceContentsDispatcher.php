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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

/**
 * Discriminator for `ResourceContents` payloads, keyed on the presence of `text` versus `blob`.
 *
 * @internal
 */
final class ResourceContentsDispatcher
{
    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @return BlobResourceContents|TextResourceContents
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data, string $context): ResourceContents
    {
        $hasText = \array_key_exists('text', $data);
        $hasBlob = \array_key_exists('blob', $data);

        if ($hasText && $hasBlob) {
            throw new \InvalidArgumentException(\sprintf('%s data must not have both "text" and "blob".', $context));
        }

        if ($hasText) {
            return TextResourceContents::fromArray($data);
        }

        if ($hasBlob) {
            return BlobResourceContents::fromArray($data);
        }

        throw new \InvalidArgumentException(\sprintf('%s data must have either "text" or "blob".', $context));
    }
}
