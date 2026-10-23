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

namespace Nexus\Mcp\Tests\Server\Transport;

use Amp\ByteStream\BufferedReader;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Starts a real stdio server, declaring no coverage so Infection never selects it and scores a false kill.
 *
 * @internal
 */
#[CoversNothing]
#[Group('unit-tests')]
#[Group('server-tests')]
final class StdioServerProcessTest extends AbstractMcpTestCase
{
    private const string SERVER = __DIR__.'/../../Fixtures/Server/Transport/stdio-server.php';

    public function testEachRequestIsAnsweredWhileStdinStaysOpen(): void
    {
        $server = Process::start([\PHP_BINARY, self::SERVER]);

        try {
            $responses = new BufferedReader($server->getStdout());

            foreach ([1, 2] as $id) {
                $server->getStdin()->write(\sprintf("{\"jsonrpc\":\"2.0\",\"id\":%d,\"method\":\"ping\"}\n", $id));
                $response = json_decode($responses->readUntil("\n", new TimeoutCancellation(5.0)), true);

                self::assertIsArray($response);
                self::assertSame($id, $response['id'] ?? null);
            }

            $server->getStdin()->close();

            self::assertSame(0, $server->join(new TimeoutCancellation(5.0)));
        } finally {
            $server->kill();
        }
    }
}
