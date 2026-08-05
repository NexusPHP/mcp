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

namespace Nexus\Mcp\Tests\Extension\Auth\ClientCredentials;

use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientCredentials::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientCredentialsTest extends TestCase
{
    public function testPinsTheProtocolVocabulary(): void
    {
        self::assertSame([
            'IDENTIFIER' => 'io.modelcontextprotocol/oauth-client-credentials',
            'GRANT_TYPE' => 'client_credentials',
            'CLIENT_ASSERTION_TYPE' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        ], new \ReflectionClass(ClientCredentials::class)->getConstants());
    }
}
