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
use Nexus\Mcp\Extension\Auth\ClientCredentials\PrivateKeyJwtCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PrivateKeyJwtCredential::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class PrivateKeyJwtCredentialTest extends TestCase
{
    public function testItCarriesTheSigningIdentity(): void
    {
        $credential = new PrivateKeyJwtCredential('the-client', '-----BEGIN PRIVATE KEY-----', 'ES256', 'key-1');

        self::assertSame('the-client', $credential->clientId);
        self::assertSame('-----BEGIN PRIVATE KEY-----', $credential->privateKeyPem);
        self::assertSame('ES256', $credential->algorithm);
        self::assertSame('key-1', $credential->keyId);
    }

    public function testTheKeyIdIsOptional(): void
    {
        self::assertNull(new PrivateKeyJwtCredential('the-client', '-----BEGIN PRIVATE KEY-----', 'ES256')->keyId);
    }

    public function testAnEmptyClientIdIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"clientId" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new PrivateKeyJwtCredential('', '-----BEGIN PRIVATE KEY-----', 'ES256');
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"privateKeyPem" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new PrivateKeyJwtCredential('the-client', '', 'ES256');
    }

    public function testAnEmptyAlgorithmIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"algorithm" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new PrivateKeyJwtCredential('the-client', '-----BEGIN PRIVATE KEY-----', '');
    }

    public function testAnEmptyKeyIdIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"keyId" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new PrivateKeyJwtCredential('the-client', '-----BEGIN PRIVATE KEY-----', 'ES256', '');
    }
}
