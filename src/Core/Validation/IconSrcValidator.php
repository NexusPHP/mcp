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

namespace Nexus\Mcp\Core\Validation;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Icon;

/**
 * Authoring-side guard holding an icon `src` to an HTTP/HTTPS URL or a base64 `data:` URI.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#icon
 */
final class IconSrcValidator
{
    /**
     * @param null|list<Icon>  $icons
     * @param non-empty-string $context Label prefix for the error message (e.g. "tool")
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(?array $icons, string $context): void
    {
        foreach ($icons ?? [] as $icon) {
            Assert::that($icon->src)->matchesRegularExpression(
                '/\A(?:https?:\/\/\S+|data:[^,]*;base64,[A-Za-z0-9+\/]+={0,2})\z/',
                \sprintf('%s "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, {value} given.', $context),
            );
        }
    }
}
