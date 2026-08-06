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

namespace Nexus\Mcp\Tests\Client\Handler\Notification;

use Nexus\Mcp\Client\Dispatch\ProgressListenerRegistry;
use Nexus\Mcp\Client\Handler\Notification\RoutingProgressNotificationHandler;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RoutingProgressNotificationHandler::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class RoutingProgressNotificationHandlerTest extends TestCase
{
    public function testRoutesMatchingTokenToTheRegisteredListener(): void
    {
        $registry = new ProgressListenerRegistry();
        $received = [];
        $registry->register(
            new ProgressToken(token: 'match'),
            static function (float $progress, ?float $total, ?string $message) use (&$received): void {
                $received = [$progress, $total, $message];
            },
        );

        $fallbackCalled = false;
        $fallback = new ClosureNotificationHandler(static function () use (&$fallbackCalled): void {
            $fallbackCalled = true;
        });

        $handler = new RoutingProgressNotificationHandler($registry, $fallback);
        $handler->handle(self::progressNotification('match', 0.5, 1.0, 'halfway'));

        self::assertSame([0.5, 1.0, 'halfway'], $received);
        self::assertFalse($fallbackCalled, 'A matched token must not also reach the fallback.');
    }

    public function testDelegatesUnmatchedTokenToTheFallback(): void
    {
        $registry = new ProgressListenerRegistry();
        $delivered = null;
        $fallback = new ClosureNotificationHandler(static function (JsonRpcNotification $n) use (&$delivered): void {
            $delivered = $n;
        });

        $handler = new RoutingProgressNotificationHandler($registry, $fallback);
        $notification = self::progressNotification('orphan', 0.25);
        $handler->handle($notification);

        self::assertSame($notification, $delivered);
    }

    public function testUnmatchedTokenWithoutFallbackIsANoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $handler = new RoutingProgressNotificationHandler(new ProgressListenerRegistry());
        $handler->handle(self::progressNotification('orphan', 1.0));
    }

    /**
     * @param int|non-empty-string $token
     */
    private static function progressNotification(
        int|string $token,
        float $progress,
        ?float $total = null,
        ?string $message = null,
    ): ProgressNotification {
        return new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: new ProgressToken(token: $token), progress: $progress, total: $total, message: $message),
        );
    }
}
