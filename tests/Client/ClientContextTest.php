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

namespace Nexus\Mcp\Tests\Client;

use Amp\NullCancellation;
use Nexus\Mcp\Client\ClientContext;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientContext::class)]
#[CoversClass(AbstractContext::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientContextTest extends TestCase
{
    public function testExposesTheConstructorFieldsInheritedFromAbstractContext(): void
    {
        $sender = new RecordingSender();
        $id = new RequestId(42);
        $meta = new RequestMetaObject();

        $context = new ClientContext($id, new NullCancellation(), $meta, 'sess-abc', $sender);

        self::assertSame($id, $context->requestId);
        self::assertSame($meta, $context->meta);
        self::assertSame('sess-abc', $context->sessionId);
    }

    public function testReportProgressDelegatesToTheInheritedSenderWhenAProgressTokenIsSet(): void
    {
        $sender = new RecordingSender();
        $meta = new RequestMetaObject(progressToken: new ProgressToken('tok-1'));

        $context = new ClientContext(new RequestId(1), new NullCancellation(), $meta, null, $sender);

        $context->reportProgress(0.5, 1.0, 'halfway');

        self::assertCount(1, $sender->notifications);
        self::assertInstanceOf(ProgressNotification::class, $sender->notifications[0]);
    }
}
