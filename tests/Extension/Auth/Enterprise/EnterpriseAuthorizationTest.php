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

namespace Nexus\Mcp\Tests\Extension\Auth\Enterprise;

use Nexus\Mcp\Extension\Auth\Enterprise\EnterpriseAuthorization;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(EnterpriseAuthorization::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class EnterpriseAuthorizationTest extends AbstractMcpTestCase
{
    public function testPinsTheProtocolVocabulary(): void
    {
        self::assertSame([
            'IDENTIFIER' => 'io.modelcontextprotocol/enterprise-managed-authorization',
            'TOKEN_EXCHANGE_GRANT_TYPE' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'JWT_BEARER_GRANT_TYPE' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'ID_JAG_TOKEN_TYPE' => 'urn:ietf:params:oauth:token-type:id-jag',
            'GRANT_PROFILE' => 'urn:ietf:params:oauth:grant-profile:id-jag',
            'JWT_TYP' => 'oauth-id-jag+jwt',
        ], (new \ReflectionClass(EnterpriseAuthorization::class))->getConstants());
    }
}
