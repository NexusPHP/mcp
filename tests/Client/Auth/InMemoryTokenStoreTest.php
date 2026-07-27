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

    public function testReadReturnsNullForAnUnknownResource(): void
    {
        self::assertNull(new InMemoryTokenStore()->read(self::RESOURCE));
    }

    public function testWriteThenReadReturnsTheToken(): void
    {
        $store = new InMemoryTokenStore();
        $token = new AccessToken('the-token', self::ISSUER);

        $store->write(self::RESOURCE, $token);

        self::assertSame($token, $store->read(self::RESOURCE));
    }

    public function testWriteReplacesAnEarlierToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('first', self::ISSUER));
        $store->write(self::RESOURCE, new AccessToken('second', self::ISSUER));

        self::assertSame('second', $store->read(self::RESOURCE)?->value);
    }

    public function testTokensAreKeptApartPerResource(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('first', self::ISSUER));
        $store->write('https://other.example.com/mcp', new AccessToken('second', self::ISSUER));

        self::assertSame('first', $store->read(self::RESOURCE)?->value);
        self::assertSame('second', $store->read('https://other.example.com/mcp')?->value);
    }

    public function testForgetDropsTheToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-token', self::ISSUER));

        $store->forget(self::RESOURCE);

        self::assertNull($store->read(self::RESOURCE));
    }

    public function testForgetLeavesOtherResourcesAlone(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('first', self::ISSUER));
        $store->write('https://other.example.com/mcp', new AccessToken('second', self::ISSUER));

        $store->forget(self::RESOURCE);

        self::assertSame('second', $store->read('https://other.example.com/mcp')?->value);
    }
}
