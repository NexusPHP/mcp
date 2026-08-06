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
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ServerContext::class)]
#[CoversClass(AbstractContext::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerContextTest extends AbstractMcpTestCase
{
    public function testCarriesAllProvidedFields(): void
    {
        $requestId = new RequestId(id: 42);
        $cancellation = new NullCancellation();
        $meta = RequestMetaObjectFactory::create();
        $sender = new RecordingSender();

        $context = new ServerContext($requestId, $cancellation, $meta, $sender);

        self::assertSame($requestId, $context->requestId);
        self::assertSame($cancellation, $context->cancellation);
        self::assertSame($meta, $context->meta);
    }

    public function testDefaultsToAnEmptyReceiveContext(): void
    {
        $context = new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );

        self::assertNull($context->receiveContext->request);
    }

    public function testCarriesTheProvidedReceiveContext(): void
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/');
        $receiveContext = new ReceiveContext($request);

        $context = new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
            $receiveContext,
        );

        self::assertSame($receiveContext, $context->receiveContext);
        self::assertSame($request, $context->receiveContext->request);
    }

    public function testAcceptsExtraFreeMeta(): void
    {
        $context = new ServerContext(
            new RequestId(id: 'req-1'),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );

        self::assertSame(RequestMetaObjectFactory::shape(), $context->meta->toArray());
    }

    public function testReportProgressSendsNotificationWhenProgressTokenPresent(): void
    {
        $token = new ProgressToken(token: 'tok-1');
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(progressToken: $token),
            $sender,
        );

        $context->reportProgress(0.5, 1.0, 'halfway');

        $expected = new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: $token, progress: 0.5, total: 1.0, message: 'halfway'),
        );

        self::assertCount(1, $sender->notifications);
        self::assertSame($expected->toArray(), $sender->notifications[0]->toArray());
    }

    public function testReportProgressOmitsEmptyMessage(): void
    {
        $token = new ProgressToken(token: 'tok-1');
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(progressToken: $token),
            $sender,
        );

        $context->reportProgress(0.5, 1.0, '');

        $expected = new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: $token, progress: 0.5, total: 1.0),
        );

        self::assertCount(1, $sender->notifications);
        self::assertSame($expected->toArray(), $sender->notifications[0]->toArray());
    }

    public function testReportProgressNoOpsWhenMetaLacksProgressToken(): void
    {
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
        );

        $context->reportProgress(0.5);

        self::assertSame([], $sender->notifications);
    }
}
