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
use Amp\Process\ProcessException;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Transport\ScriptedSubprocessLauncher;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * Drives the transport against a scripted subprocess, since a real spawn cannot validate an Infection mutant and lives in `AmpSubprocessLauncherTest`.
 *
 * @internal
 */
#[CoversClass(StdioClientTransport::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class StdioClientTransportTest extends AbstractMcpTestCase
{
    private const array COMMAND = ['mcp-server', '--stdio'];

    public function testEmptyCommandThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Stdio client command must not be empty.');

        // @phpstan-ignore argument.type (deliberately empty to exercise the runtime guard)
        new StdioClientTransport([]);
    }

    public function testASingleElementCommandIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        new StdioClientTransport(['mcp-server'], launcher: new ScriptedSubprocessLauncher());
    }

    public function testCommandThatIsNotAListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Stdio client command must be a list, array given.');

        // @phpstan-ignore argument.type
        new StdioClientTransport(['bin' => 'mcp-server']);
    }

    public function testListenerRegistrationReturnsDistinctSubscriptionsPerChannel(): void
    {
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher());

        $error = $transport->onError(static function (): void {});
        $drain = $transport->onDrain(static function (): void {});
        $close = $transport->onClose(static function (): void {});

        self::assertNotSame($error, $drain);
        self::assertNotSame($drain, $close);
        self::assertNotSame($error, $close);
    }

    public function testSendBeforeStartThrows(): void
    {
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher());

        $this->expectException(TransportNotStartedException::class);

        $transport->send($this->buildRequest());
    }

    public function testStartLaunchesTheConfiguredCommandInTheConfiguredDirectory(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = new StdioClientTransport(self::COMMAND, '/tmp', ['PATH' => '/usr/bin'], launcher: $launcher);

        $transport->start();
        $transport->close();

        self::assertCount(1, $launcher->launches);
        self::assertSame(self::COMMAND, $launcher->lastLaunch()['command']);
        self::assertSame('/tmp', $launcher->lastLaunch()['workingDirectory']);
        self::assertSame(['PATH' => '/usr/bin'], $launcher->lastLaunch()['environment']);
    }

    public function testStartLaunchesWithThePrunedDefaultEnvironmentWhenNoneIsConfigured(): void
    {
        putenv('MCP_PARENT_SECRET=topsecret');

        try {
            $launcher = new ScriptedSubprocessLauncher();
            $transport = $this->buildTransport($launcher);

            $transport->start();
            $transport->close();

            $environment = $launcher->lastLaunch()['environment'];
            self::assertArrayHasKey('PATH', $environment, 'PATH is allowlisted and inherited.');
            self::assertArrayNotHasKey('MCP_PARENT_SECRET', $environment);
        } finally {
            putenv('MCP_PARENT_SECRET');
        }
    }

    public function testStartLaunchesWithAnEmptyEnvironmentVerbatim(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = new StdioClientTransport(self::COMMAND, env: [], launcher: $launcher);

        $transport->start();
        $transport->close();

        self::assertSame([], $launcher->lastLaunch()['environment']);
    }

    public function testStartLogsTheSpawnedSubprocess(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher(), $logger);

        $transport->start();
        $transport->close();

        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Stdio client transport spawned subprocess. Command: {command} ({argumentCount} arguments, PID {pid}).',
        );
        self::assertCount(1, $matches);
        self::assertSame(
            ['command' => 'mcp-server', 'argumentCount' => 1, 'pid' => 4_242],
            $matches[0]['context'],
        );
    }

    public function testStartLogsNoSubprocessArgument(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioClientTransport(
            ['mcp-server', '--api-key', 's3cr3t-value'],
            logger: $logger,
            launcher: new ScriptedSubprocessLauncher(),
        );

        $transport->start();
        $transport->close();

        self::assertStringNotContainsString('s3cr3t-value', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public function testStartAfterStartThrowsWithoutSpawningASecondSubprocess(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        $transport->start();

        try {
            $this->expectException(TransportAlreadyStartedException::class);
            $this->expectExceptionMessageIs(\sprintf('%s has already been started.', StdioClientTransport::class));

            $transport->start();
        } finally {
            self::assertCount(1, $launcher->subprocesses);

            $transport->close();
        }
    }

    public function testAConcurrentStartDuringTheLaunchTearsItsOwnSubprocessDown(): void
    {
        $launcher = new ScriptedSubprocessLauncher(launchDelay: 0.01);
        $transport = $this->buildTransport($launcher);

        $first = async(static fn() => $transport->start());
        $second = async(static function () use ($transport): void {
            delay(0.005);
            $transport->start();
        });

        $first->await();

        try {
            $second->await();
            self::fail('Expected the second start to throw.');
        } catch (TransportAlreadyStartedException) {
        }

        self::assertCount(2, $launcher->subprocesses);
        $abandoned = $launcher->lastSubprocess();
        self::assertTrue($abandoned->getStdin()->isClosed());
        self::assertSame(1, $abandoned->killCount);

        $transport->close();
    }

    public function testStartAfterCloseThrowsWithoutSpawningASubprocess(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        $transport->close();

        try {
            $this->expectException(TransportAlreadyClosedException::class);

            $transport->start();
        } finally {
            self::assertCount(0, $launcher->subprocesses);
        }
    }

    public function testAFailedLaunchSurfacesToTheCaller(): void
    {
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher(new ProcessException('boom')));

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessageIs('boom');

        $transport->start();
    }

    public function testCloseBeforeStartIsNoOp(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);

        $transport->close();
        $transport->close();

        self::assertSame([], $launcher->subprocesses);
    }

    public function testCloseShutsTheSubprocessStdinAndKillsIt(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        $transport->start();
        $subprocess = $launcher->lastSubprocess();

        self::assertFalse($subprocess->getStdin()->isClosed());
        self::assertSame(0, $subprocess->killCount);

        $transport->close();

        self::assertTrue($subprocess->getStdin()->isClosed());
        self::assertSame(1, $subprocess->killCount);
    }

    public function testCloseLogsAtInfoLevel(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher(), $logger);
        $transport->start();
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closed.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Stdio client'], $matches[0]['context']);
    }

    public function testStartLogsTheSpawnBeforeTheDuplexStart(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher(), $logger);
        $transport->start();

        $lifecycle = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => \in_array($record['message'], [
                'Stdio client transport spawned subprocess. Command: {command} ({argumentCount} arguments, PID {pid}).',
                '{label} transport started.',
            ], true),
        ));
        self::assertCount(2, $lifecycle);
        self::assertSame('Stdio client transport spawned subprocess. Command: {command} ({argumentCount} arguments, PID {pid}).', $lifecycle[0]['message']);
        self::assertSame('{label} transport started.', $lifecycle[1]['message']);

        $transport->close();
    }

    public function testCloseLogsTheEndOfTheExitWatchAtDebug(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher(), $logger);
        $transport->start();
        $transport->close();
        delay(0);

        $matches = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio client transport stopped watching for the subprocess exit.');
        self::assertCount(1, $matches);
        self::assertSame([], $matches[0]['context']);
    }

    public function testDeliversAnEnvelopeReadFromTheSubprocessStdout(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        /** @var DeferredFuture<array<string, mixed>> $messageReceived */
        $messageReceived = new DeferredFuture();
        $transport->onMessage(static function (array $envelope) use ($messageReceived): void {
            if (! $messageReceived->isComplete()) {
                $messageReceived->complete($envelope);
            }
        });

        $transport->start();
        $launcher->lastSubprocess()->emitStdout('{"jsonrpc":"2.0","id":"round-trip-1","method":"server/discover"}'."\n");

        $envelope = $messageReceived->getFuture()->await();
        $transport->close();

        self::assertSame('2.0', $envelope['jsonrpc'] ?? null);
        self::assertSame('round-trip-1', $envelope['id'] ?? null);
        self::assertSame('server/discover', $envelope['method'] ?? null);
    }

    public function testSendsANotificationToTheSubprocessStdinAndLogsItAtDebug(): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);

        $transport->start();
        $transport->send(new ToolListChangedNotification());
        $written = $launcher->lastSubprocess()->readWrittenLine();
        $transport->close();

        self::assertSame('{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}'."\n", $written);
        $matches = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport sent {kind}.');
        self::assertNotEmpty($matches);
        self::assertSame('Stdio client', $matches[0]['context']['label'] ?? null);
        self::assertSame('"notifications/tools/list_changed" notification', $matches[0]['context']['kind'] ?? null);
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $transport = $this->buildTransport(new ScriptedSubprocessLauncher());
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send($this->buildRequest());
    }

    #[DataProvider('provideSubprocessStderrIsSanitisedAndForwardedToTheLoggerCases')]
    public function testSubprocessStderrIsSanitisedAndForwardedToTheLogger(string $emitted, string $logged): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);

        $transport->start();
        $launcher->lastSubprocess()->emitStderr($emitted."\n");
        EventLoop::run();
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'Subprocess stderr: {line}');
        self::assertCount(1, $matches);
        self::assertSame(['line' => $logged], $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideSubprocessStderrIsSanitisedAndForwardedToTheLoggerCases(): iterable
    {
        yield 'printable line' => ['mcp-server fixture ready', 'mcp-server fixture ready'];

        yield 'terminal control bytes' => ["noise\x07\x1b[31m", 'noise\\x07\\x1b[31m'];
    }

    public function testAnUnrequestedSubprocessExitNotifiesTheExitListenerWithItsCode(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited): void {
            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();
        $launcher->lastSubprocess()->exitWith(3);

        self::assertSame(3, $exited->getFuture()->await());

        $transport->close();
    }

    public function testAnUnrequestedSubprocessExitIsLoggedAsAWarning(): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited): void {
            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();
        $launcher->lastSubprocess()->exitWith(4);
        $exited->getFuture()->await();
        $transport->close();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Stdio client transport subprocess exited unexpectedly (code {exitCode}).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['exitCode' => 4], $matches[0]['context']);
    }

    public function testAnExitReportingNoStatusIsNotifiedAndLoggedAsUnknown(): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);
        /** @var DeferredFuture<null|int> $exited */
        $exited = new DeferredFuture();
        $observed = 0;
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($exited, &$observed): void {
            ++$observed;

            if (! $exited->isComplete()) {
                $exited->complete($exitCode);
            }
        });

        $transport->start();
        $launcher->lastSubprocess()->failToReportExit();

        self::assertNull($exited->getFuture()->await());
        self::assertSame(1, $observed);

        $transport->close();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Stdio client transport subprocess exited unexpectedly (code {exitCode}).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['exitCode' => 'unknown'], $matches[0]['context']);
    }

    public function testAnIntentionalCloseSilencesAnExitThatArrivesAfterwards(): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);
        $notified = 0;
        $transport->onUnexpectedExit(static function () use (&$notified): void {
            ++$notified;
        });

        $transport->start();
        $transport->close();
        EventLoop::run();
        $launcher->lastSubprocess()->exitWith(3);
        EventLoop::run();

        self::assertSame(0, $notified);
        self::assertSame([], $logger->recordsMatching(
            LogLevel::WARNING,
            'Stdio client transport subprocess exited unexpectedly (code {exitCode}).',
        ));
    }

    public function testDisposingTheExitSubscriptionStopsTheNotification(): void
    {
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher);
        $notified = 0;
        $subscription = $transport->onUnexpectedExit(static function () use (&$notified): void {
            ++$notified;
        });

        $transport->start();
        $subscription->dispose();
        $launcher->lastSubprocess()->exitWith(5);
        EventLoop::run();
        $transport->close();

        self::assertSame(0, $notified);
    }

    public function testAThrowingExitListenerNeitherStopsTheNextOneNorEscapes(): void
    {
        $logger = new ArrayLogger();
        $launcher = new ScriptedSubprocessLauncher();
        $transport = $this->buildTransport($launcher, $logger);
        /** @var DeferredFuture<null|int> $second */
        $second = new DeferredFuture();
        $failure = new \RuntimeException('listener blew up');
        $transport->onUnexpectedExit(static function () use ($failure): void {
            throw $failure;
        });
        $transport->onUnexpectedExit(static function (?int $exitCode) use ($second): void {
            if (! $second->isComplete()) {
                $second->complete($exitCode);
            }
        });

        $transport->start();
        $launcher->lastSubprocess()->exitWith(8);

        self::assertSame(8, $second->getFuture()->await());

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Stdio client transport exit listener threw.');
        self::assertCount(1, $matches);
        self::assertSame(['exception' => $failure], $matches[0]['context']);

        $transport->close();
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

    public function testDefaultEnvironmentMatchesInheritedNamesCaseInsensitively(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'Path' => 'C:\\Windows\\system32',
            'windir' => 'C:\\Windows',
            'MCP_SECRET' => 'topsecret',
        ]);

        self::assertSame(['PATH' => 'C:\\Windows\\system32'], $environment);
    }

    public function testDefaultEnvironmentPrefersAnExactNameOverACaseVariant(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'Path' => 'C:\\wrong',
            'PATH' => '/usr/bin',
        ]);

        self::assertSame(['PATH' => '/usr/bin'], $environment);
    }

    public function testDefaultEnvironmentKeepsTheFirstOfTwoCaseVariants(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'Path' => 'C:\\first',
            'path' => 'C:\\second',
        ]);

        self::assertSame(['PATH' => 'C:\\first'], $environment);
    }

    public function testDefaultEnvironmentSkipsExportedShellFunctionValues(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'PATH' => '() { :; }; echo pwned',
            'HOME' => '/home/me',
        ]);

        self::assertSame(['HOME' => '/home/me'], $environment);
    }

    public function testDefaultEnvironmentKeepsNamesFollowingASkippedOne(): void
    {
        $environment = StdioClientTransport::buildDefaultEnvironment([
            'APPDATA' => '() { :; }; echo pwned',
            'USERPROFILE' => 'C:\\Users\\me',
        ]);

        self::assertSame(['USERPROFILE' => 'C:\\Users\\me'], $environment);
    }

    private function buildTransport(
        ScriptedSubprocessLauncher $launcher,
        ?ArrayLogger $logger = null,
    ): StdioClientTransport {
        return new StdioClientTransport(
            self::COMMAND,
            logger: $logger ?? new ArrayLogger(),
            launcher: $launcher,
        );
    }

    private function buildRequest(): DiscoverRequest
    {
        return new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
        );
    }
}
