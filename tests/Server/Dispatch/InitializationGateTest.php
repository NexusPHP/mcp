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

namespace Nexus\Mcp\Tests\Server\Dispatch;

use Nexus\Mcp\Server\Dispatch\InitializationGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializationGate::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InitializationGateTest extends TestCase
{
    public function testStartsUninitialized(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->isInitialized());
    }

    public function testAllowsInitializeBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->allowsRequest('initialize'));
    }

    public function testAllowsPingBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->allowsRequest('ping'));
    }

    public function testRejectsArbitraryRequestBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->allowsRequest('tools/list'));
        self::assertFalse($gate->allowsRequest('prompts/get'));
        self::assertFalse($gate->allowsRequest('resources/read'));
    }

    public function testMarkInitializedFromAwaitingStateIsRejected(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->markInitialized());
        self::assertFalse($gate->isInitialized());
        self::assertFalse($gate->allowsRequest('tools/list'));
    }

    public function testMarkInitializeInFlightTransitionsFromAwaiting(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->markInitializeInFlight());
        self::assertFalse($gate->isInitialized(), 'Gate must not be considered initialized until "notifications/initialized" arrives.');
        self::assertFalse($gate->allowsRequest('tools/list'));
    }

    public function testMarkInitializeInFlightIsNoOpWhenAlreadyInFlight(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();

        self::assertFalse($gate->markInitializeInFlight());
    }

    public function testMarkInitializeInFlightIsNoOpAfterFullHandshake(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();
        $gate->markInitialized();

        self::assertFalse($gate->markInitializeInFlight());
        self::assertTrue($gate->isInitialized());
    }

    public function testFullHandshakeFlipsTheGate(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->markInitializeInFlight());
        self::assertTrue($gate->markInitializeCompleted());
        self::assertTrue($gate->markInitialized());

        self::assertTrue($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('tools/list'));
        self::assertTrue($gate->allowsRequest('prompts/get'));
    }

    public function testMarkInitializeCompletedTransitionsFromInFlight(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();

        self::assertTrue($gate->markInitializeCompleted());
        self::assertFalse($gate->isInitialized(), 'Completed state still requires "notifications/initialized" before the gate flips.');
        self::assertFalse($gate->allowsRequest('tools/list'));
    }

    public function testMarkInitializeCompletedIsNoOpFromAwaiting(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->markInitializeCompleted());
    }

    public function testMarkInitializeCompletedIsNoOpAfterFullHandshake(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();
        $gate->markInitialized();

        self::assertFalse($gate->markInitializeCompleted());
    }

    public function testMarkInitializedFromInFlightBuffersTheNotificationUntilTheHandlerCompletes(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();

        self::assertTrue(
            $gate->markInitialized(),
            'A same-tick "notifications/initialized" must be buffered (accepted, not dropped) so a spec-allowed pipelining client is not wedged.',
        );
        self::assertFalse($gate->isInitialized(), 'Gate stays in-flight until the handler completes.');
        self::assertFalse($gate->allowsRequest('tools/list'), 'Feature requests must remain blocked while the handler is still running.');
    }

    public function testBufferedNotificationConsumedByMarkInitializeCompletedFlipsStraightToInitialized(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();

        self::assertTrue($gate->markInitializeCompleted());
        self::assertTrue(
            $gate->isInitialized(),
            'When the buffer holds a pending "notifications/initialized", the handler-completion transition skips InitializeCompleted and lands on Initialized directly.',
        );
    }

    public function testSecondInitializedNotificationDuringInFlightIsRejectedAsDuplicate(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();

        self::assertFalse(
            $gate->markInitialized(),
            'A duplicate "notifications/initialized" must not silently re-buffer. Two calls land one WARN log.',
        );
    }

    public function testRevertInitializeInFlightClearsTheBufferedNotificationFlag(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();
        $gate->revertInitializeInFlight();

        self::assertTrue($gate->markInitializeInFlight(), 'Retry handshake must succeed after revert.');
        self::assertTrue($gate->markInitializeCompleted(), 'Retry handler completion must land on InitializeCompleted, not Initialized. The prior buffered notification was cleared.');
        self::assertFalse($gate->isInitialized());
    }

    public function testRejectsInitializeOnceHandshakeIsInFlight(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();

        self::assertFalse($gate->allowsRequest('initialize'));
    }

    public function testRejectsInitializeAfterHandlerCompletedButBeforeNotification(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();

        self::assertFalse($gate->allowsRequest('initialize'));
    }

    public function testRejectsInitializeAfterFullHandshake(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();
        $gate->markInitialized();

        self::assertFalse($gate->allowsRequest('initialize'));
    }

    public function testAllowsPingInEveryState(): void
    {
        $gate = new InitializationGate();
        self::assertTrue($gate->allowsRequest('ping'));

        $gate->markInitializeInFlight();
        self::assertTrue($gate->allowsRequest('ping'));

        $gate->markInitializeCompleted();
        self::assertTrue($gate->allowsRequest('ping'));

        $gate->markInitialized();
        self::assertTrue($gate->allowsRequest('ping'));
    }

    public function testMarkInitializedIsNoOpAfterFullHandshake(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();
        $gate->markInitialized();

        self::assertFalse($gate->markInitialized());
        self::assertTrue($gate->isInitialized());
    }

    public function testRevertInitializeInFlightFromInFlightStateRestoresAwaiting(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();

        self::assertTrue($gate->revertInitializeInFlight());
        self::assertFalse($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('initialize'), 'Gate must accept a retry initialize after revert.');
    }

    public function testRevertInitializeInFlightIsNoOpFromAwaitingState(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->revertInitializeInFlight());
        self::assertTrue($gate->allowsRequest('initialize'));
    }

    public function testRevertInitializeInFlightIsNoOpFromCompletedState(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();

        self::assertFalse(
            $gate->revertInitializeInFlight(),
            'Once the handler has completed, revert is no longer applicable.',
        );
    }

    public function testRevertInitializeInFlightIsNoOpAfterFullHandshake(): void
    {
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitializeCompleted();
        $gate->markInitialized();

        self::assertFalse($gate->revertInitializeInFlight());
        self::assertTrue($gate->isInitialized());
    }
}
