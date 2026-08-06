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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientSecretCredential;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientSecretCredential::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientSecretCredentialTest extends AbstractMcpTestCase
{
    public function testItCarriesTheCredentialPair(): void
    {
        $credential = new ClientSecretCredential('the-client', 'the-secret');

        self::assertSame('the-client', $credential->clientId);
        self::assertSame('the-secret', $credential->clientSecret);
    }

    public function testAnEmptyClientIdIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"clientId" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ClientSecretCredential('', 'the-secret');
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"clientSecret" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ClientSecretCredential('the-client', '');
    }
}
