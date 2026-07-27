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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryClientRegistrationStore::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class InMemoryClientRegistrationStoreTest extends TestCase
{
    private const string ISSUER = 'https://auth.example.com';

    public function testReadReturnsNullForAnUnknownIssuer(): void
    {
        self::assertNull(new InMemoryClientRegistrationStore()->read(self::ISSUER));
    }

    public function testWriteThenReadReturnsTheRegistration(): void
    {
        $store = new InMemoryClientRegistrationStore();
        $registration = new ClientRegistration('the-client', self::ISSUER);

        $store->write(self::ISSUER, $registration);

        self::assertSame($registration, $store->read(self::ISSUER));
    }

    public function testWriteReplacesAnEarlierRegistration(): void
    {
        $store = new InMemoryClientRegistrationStore();
        $store->write(self::ISSUER, new ClientRegistration('first', self::ISSUER));
        $store->write(self::ISSUER, new ClientRegistration('second', self::ISSUER));

        self::assertSame('second', $store->read(self::ISSUER)?->clientId);
    }

    public function testRegistrationsAreKeptApartPerIssuer(): void
    {
        $store = new InMemoryClientRegistrationStore();
        $store->write(self::ISSUER, new ClientRegistration('first', self::ISSUER));
        $store->write('https://other.example.com', new ClientRegistration('second', 'https://other.example.com'));

        self::assertSame('first', $store->read(self::ISSUER)?->clientId);
        self::assertSame('second', $store->read('https://other.example.com')?->clientId);
    }
}
