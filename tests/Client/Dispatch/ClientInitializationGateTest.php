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

use Nexus\Mcp\Client\Dispatch\ClientInitializationGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientInitializationGate::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientInitializationGateTest extends TestCase
{
    public function testStartsUninitialized(): void
    {
        $gate = new ClientInitializationGate();

        self::assertFalse($gate->isInitialized());
    }

    public function testAllowsInitializeBeforeHandshake(): void
    {
        $gate = new ClientInitializationGate();

        self::assertTrue($gate->allowsRequest('initialize'));
    }

    public function testAllowsPingBeforeHandshake(): void
    {
        $gate = new ClientInitializationGate();

        self::assertTrue($gate->allowsRequest('ping'));
    }

    public function testRejectsArbitraryRequestBeforeHandshake(): void
    {
        $gate = new ClientInitializationGate();

        self::assertFalse($gate->allowsRequest('tools/list'));
        self::assertFalse($gate->allowsRequest('prompts/get'));
        self::assertFalse($gate->allowsRequest('resources/read'));
    }

    public function testMarkInitializedFromAwaitingStateIsRejected(): void
    {
        $gate = new ClientInitializationGate();

        self::assertFalse($gate->markInitialized());
        self::assertFalse($gate->isInitialized());
        self::assertFalse($gate->allowsRequest('tools/list'));
    }

    public function testMarkInitializeInFlightTransitionsFromAwaiting(): void
    {
        $gate = new ClientInitializationGate();

        self::assertTrue($gate->markInitializeInFlight());
        self::assertFalse($gate->isInitialized(), 'Gate must not be considered initialized until markInitialized() is called.');
        self::assertFalse($gate->allowsRequest('tools/list'));
        self::assertFalse($gate->allowsRequest('initialize'), 'A second initialize must not be allowed while one is in flight.');
        self::assertTrue($gate->allowsRequest('ping'));
    }

    public function testMarkInitializeInFlightIsNoOpWhenAlreadyInFlight(): void
    {
        $gate = new ClientInitializationGate();
        $gate->markInitializeInFlight();

        self::assertFalse($gate->markInitializeInFlight());
    }

    public function testMarkInitializeInFlightIsNoOpAfterFullHandshake(): void
    {
        $gate = new ClientInitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();

        self::assertFalse($gate->markInitializeInFlight());
        self::assertTrue($gate->isInitialized());
    }

    public function testFullHandshakeFlipsTheGate(): void
    {
        $gate = new ClientInitializationGate();

        self::assertTrue($gate->markInitializeInFlight());
        self::assertTrue($gate->markInitialized());
        self::assertTrue($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('tools/list'));
        self::assertFalse($gate->allowsRequest('initialize'), 'A second initialize must not be allowed once the handshake completed.');
    }

    public function testMarkInitializedFromInitializedIsRejected(): void
    {
        $gate = new ClientInitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();

        self::assertFalse($gate->markInitialized());
    }

    public function testRevertRestoresAwaitingFromInFlight(): void
    {
        $gate = new ClientInitializationGate();
        $gate->markInitializeInFlight();

        self::assertTrue($gate->revertInitializeInFlight());
        self::assertFalse($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('initialize'), 'After revert, a fresh initialize must be allowed.');
    }

    public function testRevertFromAwaitingIsRejected(): void
    {
        $gate = new ClientInitializationGate();

        self::assertFalse($gate->revertInitializeInFlight());
    }

    public function testRevertFromInitializedIsRejected(): void
    {
        $gate = new ClientInitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();

        self::assertFalse($gate->revertInitializeInFlight());
        self::assertTrue($gate->isInitialized());
    }
}
