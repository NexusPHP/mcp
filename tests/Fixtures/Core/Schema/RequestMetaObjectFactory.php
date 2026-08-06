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

namespace Nexus\Mcp\Tests\Fixtures\Core\Schema;

use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;

/**
 * Builds a canonical `RequestMetaObject` for client-to-server request construction in tests.
 *
 * The client capabilities carry a non-empty `experimental` slot so the `toArray()` and
 * `jsonSerialize()` paths agree (no empty-object substitution to reconcile).
 *
 * @internal
 */
final class RequestMetaObjectFactory
{
    public const string CLIENT_NAME = 'test-client';
    public const string CLIENT_VERSION = '1.0.0';

    /**
     * @param array<string, mixed>  $extras
     * @param null|non-empty-string $protocolVersion
     */
    public static function create(
        ?ProgressToken $progressToken = null,
        array $extras = [],
        ?LoggingLevel $logLevel = null,
        ?string $protocolVersion = null,
        ?ClientCapabilities $clientCapabilities = null,
    ): RequestMetaObject {
        return new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: $protocolVersion ?? ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: self::CLIENT_NAME, version: self::CLIENT_VERSION),
            clientCapabilities: $clientCapabilities ?? new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
            logLevel: $logLevel,
            progressToken: $progressToken,
            extras: $extras,
        );
    }

    /**
     * The `toArray()` shape produced by `create()`, for embedding in expected envelopes.
     *
     * @param array<string, mixed>  $extras
     * @param null|non-empty-string $protocolVersion
     *
     * @return array<string, mixed>
     */
    public static function shape(
        ?ProgressToken $progressToken = null,
        array $extras = [],
        ?LoggingLevel $logLevel = null,
        ?string $protocolVersion = null,
        ?ClientCapabilities $clientCapabilities = null,
    ): array {
        return self::create($progressToken, $extras, $logLevel, $protocolVersion, $clientCapabilities)->toArray();
    }
}
