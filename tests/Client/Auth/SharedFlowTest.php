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

use Amp\DeferredFuture;
use Nexus\Mcp\Client\Auth\SharedFlow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(SharedFlow::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SharedFlowTest extends TestCase
{
    public function testItRunsTheFlowAndReturnsItsResult(): void
    {
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();

        self::assertSame('the-result', $flow->run('a', static fn(): string => 'the-result'));
    }

    public function testASecondCallerJoinsTheRunAlreadyUnderWay(): void
    {
        $gate = new DeferredFuture();
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();
        $runs = 0;

        $first = async(static function () use ($flow, $gate, &$runs): string {
            return $flow->run('a', static function () use ($gate, &$runs): string {
                ++$runs;
                $gate->getFuture()->await();

                return 'the-result';
            });
        });
        $second = async(static fn(): string => $flow->run('a', static fn(): string => 'the-other-result'));

        delay(0);
        $gate->complete();

        self::assertSame('the-result', $first->await());
        self::assertSame('the-result', $second->await());
        self::assertSame(1, $runs);
    }

    public function testAJoinerIsHandedTheFailureOfTheRunItJoined(): void
    {
        $gate = new DeferredFuture();
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();

        $first = async(static fn(): string => $flow->run('a', static function () use ($gate): string {
            $gate->getFuture()->await();

            throw new \RuntimeException('The flow failed.');
        }));
        $second = async(static fn(): string => $flow->run('a', static fn(): string => 'never-reached'));

        delay(0);
        $gate->complete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('The flow failed.');

        try {
            $first->await();
        } catch (\RuntimeException) {
            $second->await();
        }
    }

    public function testConcurrentRunsUnderDifferentKeysDoNotJoin(): void
    {
        $gate = new DeferredFuture();
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();

        $first = async(static fn(): string => $flow->run('a', static function () use ($gate): string {
            $gate->getFuture()->await();

            return 'a-result';
        }));
        $second = async(static fn(): string => $flow->run('b', static fn(): string => 'b-result'));

        delay(0);
        $gate->complete();

        self::assertSame('a-result', $first->await());
        self::assertSame('b-result', $second->await());
    }

    public function testALaterCallerRunsTheFlowAgain(): void
    {
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();
        $runs = 0;
        $count = static function () use (&$runs): string {
            ++$runs;

            return 'the-result';
        };

        $flow->run('a', $count);
        $flow->run('a', $count);

        self::assertSame(2, $runs);
    }

    public function testAFailureNobodyJoinedIsNotReportedToTheLoopAsUnhandled(): void
    {
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();
        $caught = null;

        try {
            $flow->run('a', static fn(): string => throw new \RuntimeException('The flow failed.'));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        // Driving the loop surfaces anything the future queued for want of an awaiter.
        delay(0);

        self::assertSame('The flow failed.', $caught?->getMessage());
    }

    public function testAFailedRunLeavesTheKeyFreeForTheNextCaller(): void
    {
        /** @var SharedFlow<string> $flow */
        $flow = new SharedFlow();

        try {
            $flow->run('a', static fn(): string => throw new \RuntimeException('The flow failed.'));
            self::fail('The failure should have surfaced.');
        } catch (\RuntimeException) {
            self::assertSame('the-result', $flow->run('a', static fn(): string => 'the-result'));
        }
    }
}
