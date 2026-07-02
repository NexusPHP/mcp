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

namespace Nexus\Mcp\Tests\Core\Transport;

use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Core\Transport\TransportEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TransportEvents::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TransportEventsTest extends TestCase
{
    public function testOnMessageEmitsToEveryListenerInRegistrationOrder(): void
    {
        $events = new TransportEvents();
        $calls = [];

        $events->onMessage(static function (array $envelope) use (&$calls): void {
            $calls[] = ['first', $envelope];
        });
        $events->onMessage(static function (array $envelope) use (&$calls): void {
            $calls[] = ['second', $envelope];
        });

        $events->emitMessage(['method' => 'tools/list'], new ReceiveContext());

        self::assertSame([
            ['first', ['method' => 'tools/list']],
            ['second', ['method' => 'tools/list']],
        ], $calls);
    }

    public function testOnErrorEmitsThrowableToListeners(): void
    {
        $events = new TransportEvents();
        $caught = null;

        $events->onError(static function (\Throwable $error) use (&$caught): void {
            $caught = $error;
        });

        $original = new \RuntimeException('boom');
        $events->emitError($original);

        self::assertSame($original, $caught);
    }

    public function testOnDrainEmitsZeroArgListeners(): void
    {
        $events = new TransportEvents();
        $calls = 0;

        $events->onDrain(static function () use (&$calls): void {
            ++$calls;
        });
        $events->onDrain(static function () use (&$calls): void {
            ++$calls;
        });

        $events->emitDrain();

        self::assertSame(2, $calls);
    }

    public function testOnCloseEmitsZeroArgListeners(): void
    {
        $events = new TransportEvents();
        $calls = 0;

        $events->onClose(static function () use (&$calls): void {
            ++$calls;
        });

        $events->emitClose();

        self::assertSame(1, $calls);
    }

    public function testDisposingASubscriptionRemovesItFromEmit(): void
    {
        $events = new TransportEvents();
        $calls = [];

        $events->onMessage(static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $second = $events->onMessage(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $events->emitMessage([], new ReceiveContext());
        $second->dispose();
        $events->emitMessage([], new ReceiveContext());

        self::assertSame(['first', 'second', 'first'], $calls);
    }

    /**
     * @param \Closure(TransportEvents): SubscriptionInterface $register
     * @param 'close'|'drain'|'error'|'message'                $kind
     */
    #[DataProvider('provideOnChangeFiresPerEventKindCases')]
    public function testOnChangeFiresPerEventKind(\Closure $register, string $kind): void
    {
        $changes = [];
        $events = new TransportEvents(
            onChange: static function (string $kind, string $action, int $count) use (&$changes): void {
                $changes[] = [$kind, $action, $count];
            },
        );

        $subscription = $register($events);
        $subscription->dispose();

        self::assertSame([
            [$kind, 'register', 1],
            [$kind, 'dispose', 0],
        ], $changes);
    }

    /**
     * @return iterable<string, array{0: \Closure(TransportEvents): SubscriptionInterface, 1: 'close'|'drain'|'error'|'message'}>
     */
    public static function provideOnChangeFiresPerEventKindCases(): iterable
    {
        yield 'message' => [static fn(TransportEvents $events) => $events->onMessage(static function (): void {}), 'message'];

        yield 'error' => [static fn(TransportEvents $events) => $events->onError(static function (): void {}), 'error'];

        yield 'drain' => [static fn(TransportEvents $events) => $events->onDrain(static function (): void {}), 'drain'];

        yield 'close' => [static fn(TransportEvents $events) => $events->onClose(static function (): void {}), 'close'];
    }

    public function testOnChangeIsOptional(): void
    {
        $events = new TransportEvents();

        $events->onMessage(static function (): void {})->dispose();
        $events->onClose(static function (): void {})->dispose();

        $this->expectNotToPerformAssertions();
    }

    public function testEmitWithNoRegisteredListenersIsANoOp(): void
    {
        $events = new TransportEvents();

        $events->emitMessage(['method' => 'tools/list'], new ReceiveContext());
        $events->emitError(new \RuntimeException('x'));
        $events->emitDrain();
        $events->emitClose();

        $this->expectNotToPerformAssertions();
    }

    public function testEmitSnapshotsListenerListSoMidEmitRegistrationsRunOnTheNextEmit(): void
    {
        $events = new TransportEvents();
        $calls = [];

        $events->onMessage(static function (array $envelope) use ($events, &$calls): void {
            $calls[] = ['first', $envelope];
            $events->onMessage(static function (array $envelope) use (&$calls): void {
                $calls[] = ['late-arrival', $envelope];
            });
        });

        $events->emitMessage(['method' => 'first-emit'], new ReceiveContext());
        $events->emitMessage(['method' => 'second-emit'], new ReceiveContext());

        self::assertSame([
            ['first', ['method' => 'first-emit']],
            ['first', ['method' => 'second-emit']],
            ['late-arrival', ['method' => 'second-emit']],
        ], $calls, 'A listener registered during emit must not fire in the current emit, only in subsequent emits.');
    }
}
