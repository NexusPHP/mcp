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

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\ReadableStream;
use Amp\Process\Process;
use Nexus\Mcp\Client\Transport\AmpSubprocess;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Starts real subprocesses, alongside `AmpSubprocessLauncherTest`. Nothing here declares coverage
 * of `StdioClientTransport`, so Infection never selects these tests to validate one of its
 * mutants: a mutant cannot spawn a process, and every such run would report a false kill.
 *
 * @internal
 */
#[CoversClass(AmpSubprocess::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AmpSubprocessTest extends AbstractMcpTestCase
{
    private const string ECHO_SERVER = __DIR__.'/../../Fixtures/Client/Transport/echo-server.php';
    private const string EXITING_SERVER = __DIR__.'/../../Fixtures/Client/Transport/exiting-server.php';

    public function testTheThreeStandardStreamsAndThePidReachTheCaller(): void
    {
        $subprocess = new AmpSubprocess(Process::start([\PHP_BINARY, self::ECHO_SERVER], null, []));

        try {
            self::assertGreaterThan(0, $subprocess->getPid());
            self::assertSame('echo-server fixture ready', self::readLine($subprocess->getStderr()));

            $subprocess->getStdin()->write("ping\n");

            self::assertSame('ping', self::readLine($subprocess->getStdout()));
        } finally {
            $subprocess->getStdin()->close();
            $subprocess->kill();
        }
    }

    public function testJoinAnswersTheSubprocessExitCode(): void
    {
        $subprocess = new AmpSubprocess(Process::start([\PHP_BINARY, self::EXITING_SERVER, '7'], null, []));

        self::assertSame(7, $subprocess->join());
    }

    public function testKillEndsASubprocessThatWouldOtherwiseKeepRunning(): void
    {
        $subprocess = new AmpSubprocess(Process::start([\PHP_BINARY, self::ECHO_SERVER], null, []));

        $subprocess->kill();

        self::assertNotSame(0, $subprocess->join());
    }

    private static function readLine(ReadableStream $stream): string
    {
        return (new BufferedReader($stream))->readUntil("\n");
    }
}
