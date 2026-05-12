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
 * A revision of the Model Context Protocol identified by its release date.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle#version-negotiation
 */
final readonly class ProtocolVersion
{
    public const string LATEST_VERSION = '2025-11-25';

    /**
     * Previously published spec revisions in reverse chronological order.
     */
    public const array PREVIOUS_VERSIONS = [
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * @var non-empty-string
     */
    public string $version;

    public function __construct(string $version)
    {
        Assert::that($version)
            ->isNonEmptyString('Protocol version must be a non-empty string.')
            ->matchesRegularExpression('/\A\d{4}-\d{2}-\d{2}\z/', 'Protocol version must be in the format "YYYY-MM-DD".')
        ;

        $this->version = $version;
    }

    public static function latest(): self
    {
        return new self(self::LATEST_VERSION);
    }
}
