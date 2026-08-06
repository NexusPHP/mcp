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

use Nexus\Mcp\Core\JsonRpc\UnparsedResultEnvelope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnparsedResultEnvelope::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UnparsedResultEnvelopeTest extends AbstractMcpTestCase
{
    public function testCarriesIdAndResultPayload(): void
    {
        $id = new RequestId(id: 'req-1');
        $envelope = new UnparsedResultEnvelope($id, ['answer' => 42]);

        self::assertSame($id, $envelope->id);
        self::assertSame(['answer' => 42], $envelope->result);
    }

    public function testResultPreservesNonMapPayload(): void
    {
        $envelope = new UnparsedResultEnvelope(new RequestId(id: 7), 'opaque-string');

        self::assertSame('opaque-string', $envelope->result);
    }
}
