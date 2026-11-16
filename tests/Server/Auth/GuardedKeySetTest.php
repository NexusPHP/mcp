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

namespace Nexus\Mcp\Tests\Server\Auth;

use Firebase\JWT\Key;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Server\Auth\GuardedKeySet;
use Nexus\Mcp\Server\Exception\AccessTokenValidationUnavailableException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Auth\ThrowingKeySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GuardedKeySet::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class GuardedKeySetTest extends AbstractMcpTestCase
{
    public function testAListedKeyIsReadThrough(): void
    {
        $key = new Key('a-shared-test-secret-that-is-32b-plus', 'HS256');
        $keys = new GuardedKeySet(new \ArrayObject(['listed' => $key]));

        self::assertTrue(isset($keys['listed']));
        self::assertFalse(isset($keys['unlisted']));
        self::assertSame($key, $keys['listed']);
    }

    public function testAFailedLookupMakesValidationUnavailable(): void
    {
        $failure = new \UnexpectedValueException('HTTP Error: 502 Bad Gateway');
        $keys = new GuardedKeySet(new ThrowingKeySet($failure));

        try {
            $keys->offsetExists('listed');
            self::fail('Expected AccessTokenValidationUnavailableException.');
        } catch (AccessTokenValidationUnavailableException $e) {
            self::assertSame('The JWKS signing keys could not be fetched.', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testAKeyCannotBeSet(): void
    {
        $keys = new GuardedKeySet(new \ArrayObject());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The JWKS key set is read-only.');

        $keys['added'] = new Key('a-shared-test-secret-that-is-32b-plus', 'HS256');
    }

    public function testAKeyCannotBeUnset(): void
    {
        $keys = new GuardedKeySet(new \ArrayObject());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The JWKS key set is read-only.');

        unset($keys['listed']);
    }
}
