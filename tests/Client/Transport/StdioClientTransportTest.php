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
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(StdioClientTransport::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class StdioClientTransportTest extends TestCase
{
    private const string ECHO_SERVER = __DIR__.'/../../Fixtures/Client/Transport/echo-server.php';
    private const string EXITING_SERVER = __DIR__.'/../../Fixtures/Client/Transport/exiting-server.php';
    private const string STDERR_NOISE = __DIR__.'/../../Fixtures/Client/Transport/stderr-noise.php';
    private const string ENV_REPORTER = __DIR__.'/../../Fixtures/Client/Transport/env-reporter.php';

    public function testEmptyCommandThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Stdio client command must not be empty.');

        new StdioClientTransport([]);
    }

    public function testListenerRegistrationReturnsDistinctSubscriptionsPerChannel(): void
    {
        $transport = self::buildTransport();

        $error = $transport->onError(static fn() => null);
        $drain = $transport->onDrain(static fn() => null);
        $close = $transport->onClose(static fn() => null);

        self::assertNotSame($error, $drain);
        self::assertNotSame($drain, $close);
        self::assertNotSame($error, $close);
    }

    public function testSendBeforeStartThrows(): void
    {
        $transport = self::buildTransport();

        $this->expectException(TransportNotStartedException::class);

        $transport->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
    }

    public function testStartAfterStartThrows(): void
    {
        $transport = self::buildTransport();
        $transport->start();

        try {
            $this->expectException(TransportAlreadyStartedException::class);
            $this->expectExceptionMessageIs(\sprintf('%s has already been started.', StdioClientTransport::class));

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

    public function testAnUnrequestedSubprocessExitNotifiesTheExitListenerWithItsCode(): void
    {
        $transport = self::buildExitingTransport(3);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited): void {
            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();

        self::assertSame(3, $exited->getFuture()->await());

        $transport->close();
    }

    public function testAnUnrequestedSubprocessExitIsLoggedAsAWarning(): void
    {
        $logger = new ArrayLogger();
        $transport = self::buildExitingTransport(4, $logger);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited): void {
            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();
        $exited->getFuture()->await();
        $transport->close();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            '{label} transport subprocess exited unexpectedly (code {exitCode}).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Stdio client', 'exitCode' => 4], $matches[0]['context']);
    }

    public function testAnIntentionalCloseDoesNotNotifyTheExitListener(): void
    {
        $transport = self::buildTransport();
        $notified = 0;
        $transport->onUnexpectedExit(static function () use (&$notified): void {
            ++$notified;
        });

        $transport->start();
        $transport->close();
        EventLoop::run();

        self::assertSame(0, $notified);
    }

    public function testDisposingTheExitSubscriptionStopsTheNotification(): void
    {
        $transport = self::buildExitingTransport(5);
        $notified = 0;
        $subscription = $transport->onUnexpectedExit(static function () use (&$notified): void {
            ++$notified;
        });

        $transport->start();
        $subscription->dispose();
        EventLoop::run();
        $transport->close();

        self::assertSame(0, $notified);
    }

    public function testAThrowingExitListenerNeitherStopsTheNextOneNorEscapes(): void
    {
        $logger = new ArrayLogger();
        $transport = self::buildExitingTransport(8, $logger);
        /** @var DeferredFuture<null|int> $second */
        $second = new DeferredFuture();
        $transport->onUnexpectedExit(static function (): void {
            throw new \RuntimeException('listener blew up');
        });
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($second): void {
            if (! $second->isComplete()) {
                $second->complete($exitCode);
            }
        });

        $transport->start();

        self::assertSame(8, $second->getFuture()->await());
        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, '{label} transport exit listener threw.'));

        $transport->close();
    }

    public function testClosingAfterThePeerHasAlreadyExitedStillReportsTheExit(): void
    {
        $transport = self::buildExitingTransport(9);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited): void {
            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();
        $observed = $exited->getFuture()->await();
        $transport->close();

        self::assertSame(9, $observed);
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
        $transport->send(new DiscoverRequest(id: new RequestId(id: 'round-trip-1'), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));

        $envelope = $messageReceived->getFuture()->await();
        $transport->close();

        self::assertSame('2.0', $envelope['jsonrpc'] ?? null);
        self::assertSame('round-trip-1', $envelope['id'] ?? null);
        self::assertSame('server/discover', $envelope['method'] ?? null);
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
        $transport->send(new ToolListChangedNotification());
        $envelope = $messageReceived->getFuture()->await();
        $transport->close();

        self::assertSame('notifications/tools/list_changed', $envelope['method'] ?? null);
        $matches = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport sent {kind}.');
        self::assertNotEmpty($matches);
        self::assertSame('Stdio client', $matches[0]['context']['label'] ?? null);
        self::assertSame('"notifications/tools/list_changed" notification', $matches[0]['context']['kind'] ?? null);
    }

    public function testSubprocessStderrIsForwardedToTheLogger(): void
    {
        /** @var DeferredFuture<string> $stderrCaptured */
        $stderrCaptured = new DeferredFuture();
        $transport = new StdioClientTransport(
            [\PHP_BINARY, self::ECHO_SERVER],
            logger: self::stderrCapturingLogger($stderrCaptured),
        );

        $transport->start();
        $line = $stderrCaptured->getFuture()->await();
        $transport->close();

        self::assertSame('echo-server fixture ready', $line);
    }

    public function testSubprocessStderrIsSanitisedBeforeLogging(): void
    {
        /** @var DeferredFuture<string> $stderrCaptured */
        $stderrCaptured = new DeferredFuture();
        $transport = new StdioClientTransport(
            [\PHP_BINARY, self::STDERR_NOISE],
            logger: self::stderrCapturingLogger($stderrCaptured),
        );

        $transport->start();
        $line = $stderrCaptured->getFuture()->await();
        $transport->close();

        self::assertSame('noise\\x07\\x1b[31m', $line);
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $transport = self::buildTransport();
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
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

    #[DataProvider('provideDefaultEnvironmentKeepsEachInheritedNameCases')]
    public function testDefaultEnvironmentKeepsEachInheritedName(string $name): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([$name => 'value', 'NOT_ALLOWED' => 'secret']);

        self::assertSame([$name => 'value'], $environment);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideDefaultEnvironmentKeepsEachInheritedNameCases(): iterable
    {
        $names = [
            'APPDATA',
            'HOME',
            'HOMEDRIVE',
            'HOMEPATH',
            'LOCALAPPDATA',
            'LOGNAME',
            'PATH',
            'PROCESSOR_ARCHITECTURE',
            'SHELL',
            'SYSTEMDRIVE',
            'SYSTEMROOT',
            'TEMP',
            'TERM',
            'USER',
            'USERNAME',
            'USERPROFILE',
        ];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    public function testDefaultEnvironmentSkipsExportedShellFunctionValues(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'PATH' => '() { :; }; echo pwned',
            'HOME' => '/home/me',
        ]);

        self::assertSame(['HOME' => '/home/me'], $environment);
    }

    public function testDefaultEnvironmentPrunesParentSecretsFromTheSubprocess(): void
    {
        putenv('MCP_PARENT_SECRET=topsecret');

        try {
            $childEnv = self::reportedEnvironment(new StdioClientTransport([\PHP_BINARY, self::ENV_REPORTER]));

            self::assertArrayHasKey('PATH', $childEnv, 'PATH is allowlisted and inherited.');
            self::assertArrayNotHasKey('MCP_PARENT_SECRET', $childEnv);
        } finally {
            putenv('MCP_PARENT_SECRET');
        }
    }

    public function testEmptyEnvironmentInheritsTheFullParentEnvironment(): void
    {
        putenv('MCP_PARENT_SECRET=topsecret');

        try {
            $childEnv = self::reportedEnvironment(new StdioClientTransport([\PHP_BINARY, self::ENV_REPORTER], env: []));

            self::assertSame('topsecret', $childEnv['MCP_PARENT_SECRET'] ?? null);
        } finally {
            putenv('MCP_PARENT_SECRET');
        }
    }

    public function testExplicitEnvironmentIsPassedVerbatim(): void
    {
        $transport = new StdioClientTransport(
            [\PHP_BINARY, self::ENV_REPORTER],
            env: ['MCP_CUSTOM' => 'yes', 'PATH' => (string) getenv('PATH')],
        );
        $childEnv = self::reportedEnvironment($transport);

        self::assertSame('yes', $childEnv['MCP_CUSTOM'] ?? null);
        self::assertArrayNotHasKey('HOME', $childEnv, 'A non-empty env is passed verbatim, not merged with the parent.');
    }

    private static function buildTransport(?ArrayLogger $logger = null): StdioClientTransport
    {
        return new StdioClientTransport(
            [\PHP_BINARY, self::ECHO_SERVER],
            logger: $logger ?? new ArrayLogger(),
        );
    }

    private static function buildExitingTransport(int $exitCode, ?ArrayLogger $logger = null): StdioClientTransport
    {
        return new StdioClientTransport(
            [\PHP_BINARY, self::EXITING_SERVER, (string) $exitCode],
            logger: $logger ?? new ArrayLogger(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function reportedEnvironment(StdioClientTransport $transport): array
    {
        /** @var DeferredFuture<array<string, mixed>> $received */
        $received = new DeferredFuture();
        $transport->onMessage(static function (array $envelope) use ($received): void {
            if (! $received->isComplete()) {
                $received->complete($envelope);
            }
        });

        $transport->start();
        $envelope = $received->getFuture()->await();
        $transport->close();

        $params = $envelope['params'] ?? null;
        self::assertIsArray($params);
        $environment = $params['env'] ?? null;
        self::assertIsArray($environment);

        return $environment;
    }

    /**
     * @param DeferredFuture<string> $captured
     */
    private static function stderrCapturingLogger(DeferredFuture $captured): AbstractLogger
    {
        return new class ($captured) extends AbstractLogger {
            /**
             * @param DeferredFuture<string> $captured
             */
            public function __construct(private readonly DeferredFuture $captured)
            {
            }

            #[\Override]
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if (
                    'Subprocess stderr: {line}' === (string) $message
                    && ! $this->captured->isComplete()
                    && \is_string($context['line'] ?? null)
                ) {
                    $this->captured->complete($context['line']);
                }
            }
        };
    }
}
