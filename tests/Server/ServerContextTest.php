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

namespace Nexus\Mcp\Tests\Server;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ServerContext::class)]
#[CoversClass(AbstractContext::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerContextTest extends TestCase
{
    public function testCarriesAllProvidedFields(): void
    {
        $requestId = new RequestId(42);
        $cancellation = new NullCancellation();
        $meta = new RequestMetaObject();
        $sender = new RecordingSender();

        $context = new ServerContext($requestId, $cancellation, $meta, 'sess-1', $sender);

        self::assertSame($requestId, $context->requestId);
        self::assertSame($cancellation, $context->cancellation);
        self::assertSame($meta, $context->meta);
        self::assertSame('sess-1', $context->sessionId);
    }

    public function testAcceptsEmptyMetaAndNullSessionId(): void
    {
        $context = new ServerContext(
            new RequestId('req-1'),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );

        self::assertSame([], $context->meta->toArray());
        self::assertNull($context->sessionId);
    }

    public function testReportProgressSendsNotificationWhenProgressTokenPresent(): void
    {
        $token = new ProgressToken('tok-1');
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(progressToken: $token),
            null,
            $sender,
        );

        $context->reportProgress(0.5, 1.0, 'halfway');

        $expected = new ProgressNotification(
            new ProgressNotificationParams($token, 0.5, 1.0, 'halfway'),
        );

        self::assertCount(1, $sender->notifications);
        self::assertSame($expected->toArray(), $sender->notifications[0]->toArray());
    }

    public function testReportProgressNoOpsWhenMetaLacksProgressToken(): void
    {
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            $sender,
        );

        $context->reportProgress(0.5);

        self::assertSame([], $sender->notifications);
    }

    public function testLogSendsNotificationAtTheGivenLevel(): void
    {
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            $sender,
        );

        $context->log(LoggingLevel::Warning, ['code' => 'slow', 'duration_ms' => 1200], 'tools/echo');

        $expected = new LoggingMessageNotification(
            new LoggingMessageNotificationParams(LoggingLevel::Warning, ['code' => 'slow', 'duration_ms' => 1200], 'tools/echo'),
        );

        self::assertCount(1, $sender->notifications);
        self::assertSame($expected->toArray(), $sender->notifications[0]->toArray());
    }

    public function testLogOmitsLoggerWhenNotProvided(): void
    {
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            $sender,
        );

        $context->log(LoggingLevel::Info, 'starting');

        $expected = new LoggingMessageNotification(
            new LoggingMessageNotificationParams(LoggingLevel::Info, 'starting'),
        );

        self::assertCount(1, $sender->notifications);
        self::assertSame($expected->toArray(), $sender->notifications[0]->toArray());
    }
}
