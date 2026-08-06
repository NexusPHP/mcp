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
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#protocol-version-negotiation
 */
final readonly class ProtocolVersion
{
    public const string LATEST_VERSION = '2026-07-28';

    /**
     * Superseded spec revisions, newest first.
     */
    public const array PREVIOUS_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * The protocol revisions this server implements, newest first.
     */
    public const array SUPPORTED_VERSIONS = [self::LATEST_VERSION];

    /**
     * @param non-empty-string $version
     */
    public function __construct(public string $version)
    {
        // The spec types this as a plain string, and a version the server does not recognise must reach
        // the support check so it is answered with -32022 rather than rejected as malformed params.
        Assert::that($version)->isNonEmptyString('"protocolVersion" must be a non-empty string.');
    }
}
