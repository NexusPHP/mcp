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

namespace Nexus\Mcp\Tests\Client\Transport;

use Amp\DeferredFuture;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(StdioClientTransport::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class StdioClientTransportTest extends TestCase
{
    private const string ECHO_SERVER = __DIR__.'/../../Fixtures/Client/Transport/echo-server.php';

    public function testEmptyCommandThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stdio client command must not be empty.');

        new StdioClientTransport([]);
    }

    public function testSessionIdIsAlwaysNull(): void
    {
        $transport = self::buildTransport();

        self::assertNull($transport->sessionId());
    }

    public function testSendBeforeStartThrows(): void
    {
        $transport = self::buildTransport();

        $this->expectException(TransportNotStartedException::class);

        $transport->send(new PingRequest(new RequestId(1), new EmptyRequestParams()));
    }

    public function testStartAfterStartThrows(): void
    {
        $transport = self::buildTransport();
        $transport->start();

        try {
            $this->expectException(TransportAlreadyStartedException::class);
            $this->expectExceptionMessage(\sprintf('%s has already been started.', StdioClientTransport::class));

            $transport->start();
        } finally {
            $transport->close();
        }
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = self::buildTransport();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->start();
    }

    public function testCloseBeforeStartIsNoOp(): void
    {
        $transport = self::buildTransport();

        $transport->close();
        $transport->close();

        $this->expectNotToPerformAssertions();
    }

    public function testRoundTripsAnEnvelopeAgainstTheEchoFixture(): void
    {
        $transport = self::buildTransport();
        /** @var DeferredFuture<array<string, mixed>> $messageReceived */
        $messageReceived = new DeferredFuture();
        $transport->onMessage(static function (array $envelope) use ($messageReceived): void {
            if (! $messageReceived->isComplete()) {
                $messageReceived->complete($envelope);
            }
        });

        $transport->start();
        $transport->send(new PingRequest(new RequestId('round-trip-1'), new EmptyRequestParams()));

        $envelope = $messageReceived->getFuture()->await();
        $transport->close();

        self::assertSame('2.0', $envelope['jsonrpc'] ?? null);
        self::assertSame('round-trip-1', $envelope['id'] ?? null);
        self::assertSame('ping', $envelope['method'] ?? null);
    }

    public function testSendsANotificationAndLogsItAtDebug(): void
    {
        $logger = new ArrayLogger();
        $transport = self::buildTransport(logger: $logger);
        /** @var DeferredFuture<array<string, mixed>> $messageReceived */
        $messageReceived = new DeferredFuture();
        $transport->onMessage(static function (array $envelope) use ($messageReceived): void {
            if (! $messageReceived->isComplete()) {
                $messageReceived->complete($envelope);
            }
        });

        $transport->start();
        $transport->send(new InitializedNotification());
        $envelope = $messageReceived->getFuture()->await();
        $transport->close();

        self::assertSame('notifications/initialized', $envelope['method'] ?? null);
        $matches = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport sent {kind}.');
        self::assertNotEmpty($matches);
        self::assertSame('Stdio client', $matches[0]['context']['label'] ?? null);
        self::assertSame('"notifications/initialized" notification', $matches[0]['context']['kind'] ?? null);
    }

    public function testSubprocessStderrIsForwardedToTheLogger(): void
    {
        /** @var DeferredFuture<string> $stderrCaptured */
        $stderrCaptured = new DeferredFuture();
        $logger = new class ($stderrCaptured) extends AbstractLogger {
            /**
             * @param DeferredFuture<string> $stderrCaptured
             */
            public function __construct(private readonly DeferredFuture $stderrCaptured)
            {
            }

            #[\Override]
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if (
                    'Subprocess stderr: {line}' === (string) $message
                    && ! $this->stderrCaptured->isComplete()
                    && \is_string($context['line'] ?? null)
                ) {
                    $this->stderrCaptured->complete($context['line']);
                }
            }
        };
        $transport = new StdioClientTransport([\PHP_BINARY, self::ECHO_SERVER], logger: $logger);

        $transport->start();
        $line = $stderrCaptured->getFuture()->await();
        $transport->close();

        self::assertSame('echo-server fixture ready', $line);
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $transport = self::buildTransport();
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(new PingRequest(new RequestId(1), new EmptyRequestParams()));
    }

    public function testCloseLogsAtInfoLevel(): void
    {
        $logger = new ArrayLogger();
        $transport = self::buildTransport(logger: $logger);
        $transport->start();
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closed.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Stdio client'], $matches[0]['context']);
    }

    private static function buildTransport(?ArrayLogger $logger = null): StdioClientTransport
    {
        return new StdioClientTransport(
            [\PHP_BINARY, self::ECHO_SERVER],
            logger: $logger ?? new ArrayLogger(),
        );
    }
}
