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

namespace Nexus\Mcp\Tests\Client\Dispatch;

use Nexus\Mcp\Client\Dispatch\ProgressListenerRegistry;
use Nexus\Mcp\Core\Schema\ProgressToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProgressListenerRegistry::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ProgressListenerRegistryTest extends TestCase
{
    public function testGetReturnsNullForUnregisteredToken(): void
    {
        $registry = new ProgressListenerRegistry();

        self::assertNull($registry->get(new ProgressToken('absent')));
    }

    public function testRegisterThenGetReturnsTheListener(): void
    {
        $registry = new ProgressListenerRegistry();
        $listener = static function (float $progress, ?float $total, ?string $message): void {};

        $registry->register(new ProgressToken('demo'), $listener);

        self::assertSame($listener, $registry->get(new ProgressToken('demo')));
    }

    public function testRegisterKeysByTokenValueNotIdentity(): void
    {
        $registry = new ProgressListenerRegistry();
        $listener = static function (float $progress, ?float $total, ?string $message): void {};

        $registry->register(new ProgressToken(7), $listener);

        // A distinct ProgressToken instance with the same value resolves the listener.
        self::assertSame($listener, $registry->get(new ProgressToken(7)));
    }

    public function testUnregisterRemovesTheListener(): void
    {
        $registry = new ProgressListenerRegistry();
        $registry->register(new ProgressToken('demo'), static function (float $progress, ?float $total, ?string $message): void {});

        $registry->unregister(new ProgressToken('demo'));

        self::assertNull($registry->get(new ProgressToken('demo')));
    }

    public function testUnregisterIsNoOpForUnknownToken(): void
    {
        $registry = new ProgressListenerRegistry();
        $listener = static function (float $progress, ?float $total, ?string $message): void {};
        $registry->register(new ProgressToken('keep'), $listener);

        $registry->unregister(new ProgressToken('other'));

        self::assertSame($listener, $registry->get(new ProgressToken('keep')));
    }
}
