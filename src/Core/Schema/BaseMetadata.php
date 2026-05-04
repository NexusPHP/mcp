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
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
abstract readonly class BaseMetadata
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    public function __construct(string $name, ?string $title = null)
    {
        $label = basename(strtr(static::class, '\\', '/'));

        Assert::that($name)->isNonEmptyString(\sprintf('%s name must be a non-empty string.', $label));
        Assert::that($title)->nullOr()->isNonEmptyString(\sprintf('%s title must be a non-empty string or null.', $label));

        $this->name = $name;
        $this->title = $title;
    }
}
