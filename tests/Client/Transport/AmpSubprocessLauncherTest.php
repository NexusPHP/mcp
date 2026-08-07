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
use Nexus\Mcp\Client\Transport\AmpSubprocess;
use Nexus\Mcp\Client\Transport\AmpSubprocessLauncher;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Starts real subprocesses, alongside `AmpSubprocessTest`. Nothing here declares coverage of
 * `StdioClientTransport`, so Infection never selects these tests to validate one of its mutants: a
 * mutant cannot spawn a process, and every such run would report a false kill. That is what lets the
 * last test drive the transport end to end without costing it its mutation score.
 *
 * @internal
 */
#[CoversClass(AmpSubprocessLauncher::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AmpSubprocessLauncherTest extends AbstractMcpTestCase
{
    private const string ECHO_SERVER = __DIR__.'/../../Fixtures/Client/Transport/echo-server.php';

    public function testLaunchStartsTheCommandAndAnswersAnAmpSubprocess(): void
    {
        $subprocess = (new AmpSubprocessLauncher())->launch([\PHP_BINARY, '-r', 'exit(6);'], null, []);

        self::assertInstanceOf(AmpSubprocess::class, $subprocess);
        self::assertGreaterThan(0, $subprocess->getPid());
        self::assertSame(6, $subprocess->join());
    }

    public function testLaunchRunsInTheGivenWorkingDirectory(): void
    {
        $directory = realpath(sys_get_temp_dir());
        self::assertIsString($directory);

        $subprocess = (new AmpSubprocessLauncher())->launch(
            [\PHP_BINARY, '-r', 'exit(getcwd() === $argv[1] ? 0 : 1);', $directory],
            $directory,
            [],
        );

        self::assertSame(0, $subprocess->join());
    }

    public function testANonEmptyEnvironmentReachesTheSubprocessWithNothingInherited(): void
    {
        $subprocess = (new AmpSubprocessLauncher())->launch(
            [\PHP_BINARY, '-r', 'exit(getenv("MCP_CUSTOM") === "yes" && getenv("HOME") === false ? 0 : 1);'],
            null,
            ['MCP_CUSTOM' => 'yes'],
        );

        self::assertSame(0, $subprocess->join());
    }

    public function testAnEmptyEnvironmentInheritsTheParentEnvironmentWhole(): void
    {
        putenv('MCP_PARENT_SECRET=topsecret');

        try {
            $subprocess = (new AmpSubprocessLauncher())->launch(
                [\PHP_BINARY, '-r', 'exit(getenv("MCP_PARENT_SECRET") === "topsecret" ? 0 : 1);'],
                null,
                [],
            );

            self::assertSame(0, $subprocess->join(), 'An empty environment is not pruned, it is not passed at all.');
        } finally {
            putenv('MCP_PARENT_SECRET');
        }
    }

    public function testTheStdioClientTransportLaunchesThroughThisLauncherByDefault(): void
    {
        $transport = new StdioClientTransport([\PHP_BINARY, self::ECHO_SERVER]);
        /** @var DeferredFuture<array<string, mixed>> $received */
        $received = new DeferredFuture();
        $transport->onMessage(static function (array $envelope) use ($received): void {
            if (! $received->isComplete()) {
                $received->complete($envelope);
            }
        });

        $transport->start();
        $transport->send(new ToolListChangedNotification());
        $envelope = $received->getFuture()->await();
        $transport->close();

        self::assertSame('notifications/tools/list_changed', $envelope['method'] ?? null);
    }
}
