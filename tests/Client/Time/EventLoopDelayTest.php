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

namespace Nexus\Mcp\Tests\Client\Time;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Nexus\Clock\HighResolutionStopwatch;
use Nexus\Clock\InvalidDurationException;
use Nexus\Mcp\Client\Time\EventLoopDelay;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Revolt\EventLoop;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(EventLoopDelay::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class EventLoopDelayTest extends AbstractMcpTestCase
{
    public function testSleepSuspendsForTheRequestedDuration(): void
    {
        $stopwatch = new HighResolutionStopwatch();

        $started = $stopwatch->read();
        (new EventLoopDelay())->sleep(0.02);
        $elapsed = $stopwatch->read() - $started;

        self::assertGreaterThanOrEqual(0.02, $elapsed);
        self::assertLessThan(0.5, $elapsed);
    }

    #[DataProvider('provideNoOpDurationCases')]
    public function testADurationRoundingToNothingReturnsWithoutSuspending(float|int $seconds): void
    {
        $ticked = false;
        EventLoop::defer(static function () use (&$ticked): void {
            $ticked = true;
        });

        (new EventLoopDelay())->sleep($seconds);

        self::assertFalse($ticked, 'The fiber suspended, so the loop ran the deferred callback.');
    }

    #[DataProvider('provideNoOpDurationCases')]
    public function testADurationRoundingToNothingStillObservesATriggeredCancellation(float|int $seconds): void
    {
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        $this->expectException(CancelledException::class);

        (new EventLoopDelay())->sleep($seconds, $cancellation->getCancellation());
    }

    /**
     * @return iterable<string, array{float|int}>
     */
    public static function provideNoOpDurationCases(): iterable
    {
        yield 'zero' => [0];

        yield 'negative int' => [-1];

        yield 'negative float' => [-0.5];

        yield 'under half a microsecond' => [0.000_000_4];
    }

    public function testAOneMicrosecondSleepStillSuspends(): void
    {
        $ticked = false;
        EventLoop::defer(static function () use (&$ticked): void {
            $ticked = true;
        });

        (new EventLoopDelay())->sleep(0.000_001);

        self::assertTrue($ticked, 'The fiber never suspended, so the loop never ran the deferred callback.');
    }

    public function testARunningSleepAbortsWhenItsCancellationFires(): void
    {
        $cancellation = new DeferredCancellation();
        $sleep = async(static fn() => (new EventLoopDelay())->sleep(60, $cancellation->getCancellation()))->ignore();

        $cancellation->cancel();

        $this->expectException(CancelledException::class);

        $sleep->await();
    }

    #[DataProvider('provideAnInvalidDurationIsRefusedCases')]
    public function testAnInvalidDurationIsRefused(float $seconds, string $message): void
    {
        $this->expectException(InvalidDurationException::class);
        $this->expectExceptionMessageIs($message);

        (new EventLoopDelay())->sleep($seconds);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function provideAnInvalidDurationIsRefusedCases(): iterable
    {
        yield 'not a number' => [\NAN, 'Invalid duration of NAN seconds.'];

        yield 'positive infinity' => [\INF, 'Invalid duration of INF seconds.'];

        yield 'beyond the microsecond range' => [1e13, 'Invalid duration of 10000000000000.0 seconds.'];
    }
}
