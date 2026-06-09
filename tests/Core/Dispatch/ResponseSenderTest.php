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

namespace Nexus\Mcp\Tests\Core\Dispatch;

use Nexus\Mcp\Core\Dispatch\ResponseSender;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(ResponseSender::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResponseSenderTest extends TestCase
{
    public function testSendDeliversTheMessage(): void
    {
        $transport = new RecordingTransport();
        $message = new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()));
        $sender = new ResponseSender(new ArrayLogger());

        $sender->send($transport, $message, 'server/discover');

        self::assertCount(1, $transport->sent);
        self::assertSame($message, $transport->sent[0]['message']);
    }

    public function testSendDemotesPeerHangupToInfoLog(): void
    {
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException('send');
        $logger = new ArrayLogger();
        $sender = new ResponseSender($logger);

        $sender->send($transport, new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())), 'server/discover');

        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('server/discover', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(TransportAlreadyClosedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testSendLogsOtherFailuresAsError(): void
    {
        $transport = new RecordingTransport();
        $transport->sendError = new \RuntimeException('write failed');
        $logger = new ArrayLogger();
        $sender = new ResponseSender($logger);

        $sender->send($transport, new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())), 'server/discover');

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Failed to deliver response to transport.');
        self::assertCount(1, $matches);
        self::assertSame('server/discover', $matches[0]['context']['method'] ?? null);
    }

    public function testToErrorResponseUsesTheExceptionRequestId(): void
    {
        $exception = new MethodNotFoundException('vendor/x', new RequestId(id: 7));

        $response = ResponseSender::buildErrorResponse($exception, new RequestId(id: 99));

        self::assertSame(7, $response->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $response->error->code);
        self::assertSame($exception->getMessage(), $response->error->message);
    }

    public function testToErrorResponseFallsBackWhenExceptionHasNoRequestId(): void
    {
        $response = ResponseSender::buildErrorResponse(new MethodNotFoundException('vendor/x'), new RequestId(id: 99));

        self::assertSame(99, $response->id?->id);
    }
}
