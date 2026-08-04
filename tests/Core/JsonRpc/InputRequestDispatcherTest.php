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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\JsonRpc\InputRequestDispatcher;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InputRequestDispatcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InputRequestDispatcherTest extends TestCase
{
    public function testDecodeDispatchesAnElicitRequest(): void
    {
        $request = InputRequestDispatcher::decode([
            'method' => 'elicitation/create',
            'params' => [
                'mode' => 'form',
                'message' => 'Confirm?',
                'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
        ]);

        self::assertInstanceOf(ElicitRequest::class, $request);
    }

    public function testDecodeRejectsAMissingMethod(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry is missing the required "method" key.');

        InputRequestDispatcher::decode(['params' => []]);
    }

    public function testDecodeRejectsAnUnsupportedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must use a supported input-request method, \'sampling/createMessage\' given.');

        InputRequestDispatcher::decode(['method' => 'sampling/createMessage']);
    }
}
