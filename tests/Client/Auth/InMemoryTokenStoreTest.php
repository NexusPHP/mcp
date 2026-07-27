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

use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryTokenStore::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class InMemoryTokenStoreTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testReadReturnsNullForAnUnknownPair(): void
    {
        self::assertNull(new InMemoryTokenStore()->read(self::RESOURCE, self::ISSUER));
    }

    public function testWriteThenReadReturnsTheToken(): void
    {
        $store = new InMemoryTokenStore();
        $token = new AccessToken('the-token');

        $store->write(self::RESOURCE, self::ISSUER, $token);

        self::assertSame($token, $store->read(self::RESOURCE, self::ISSUER));
    }

    public function testWriteReplacesAnEarlierToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('first'));
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('second'));

        self::assertSame('second', $store->read(self::RESOURCE, self::ISSUER)?->value);
    }

    public function testTokensAreKeptApartPerIssuer(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('first'));
        $store->write(self::RESOURCE, 'https://other.example.com', new AccessToken('second'));

        self::assertSame('first', $store->read(self::RESOURCE, self::ISSUER)?->value);
        self::assertSame('second', $store->read(self::RESOURCE, 'https://other.example.com')?->value);
    }

    public function testTokensAreKeptApartPerResource(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('first'));
        $store->write('https://other.example.com/mcp', self::ISSUER, new AccessToken('second'));

        self::assertSame('first', $store->read(self::RESOURCE, self::ISSUER)?->value);
        self::assertSame('second', $store->read('https://other.example.com/mcp', self::ISSUER)?->value);
    }

    public function testAResourceEndingInTheSeparatorDoesNotCollide(): void
    {
        $store = new InMemoryTokenStore();
        $store->write('https://mcp.example.com|a', 'b', new AccessToken('first'));
        $store->write('https://mcp.example.com', 'a|b', new AccessToken('second'));

        self::assertSame('first', $store->read('https://mcp.example.com|a', 'b')?->value);
        self::assertSame('second', $store->read('https://mcp.example.com', 'a|b')?->value);
    }

    public function testForgetDropsTheToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('the-token'));

        $store->forget(self::RESOURCE, self::ISSUER);

        self::assertNull($store->read(self::RESOURCE, self::ISSUER));
    }

    public function testForgetLeavesOtherIssuersAlone(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('first'));
        $store->write(self::RESOURCE, 'https://other.example.com', new AccessToken('second'));

        $store->forget(self::RESOURCE, self::ISSUER);

        self::assertSame('second', $store->read(self::RESOURCE, 'https://other.example.com')?->value);
    }
}
