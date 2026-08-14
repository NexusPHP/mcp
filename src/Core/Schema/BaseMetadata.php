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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;

/**
 * Base interface for metadata with name (identifier) and title (display name) properties.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
abstract readonly class BaseMetadata
{
    /**
     * @param non-empty-string      $name
     * @param null|non-empty-string $title
     */
    public function __construct(
        public string $name,
        public ?string $title = null,
    ) {
        $label = basename(strtr(static::class, '\\', '/'));

        Assert::that($name)->isNonEmptyString(\sprintf('%s name must be a non-empty string.', $label));
        Assert::that($title)->nullOr()->isNonEmptyString(\sprintf('%s title must be a non-empty string or null.', $label));
    }

    /**
     * Resolves the spec-defined display name: `title` when set, otherwise the programmatic `name`.
     *
     * @return non-empty-string
     */
    public function getDisplayName(): string
    {
        return $this->title ?? $this->name;
    }
}
