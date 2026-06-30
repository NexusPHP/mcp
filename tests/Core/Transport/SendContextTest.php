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

namespace Nexus\Mcp\Tests\Core\Transport;

use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SendContext::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SendContextTest extends TestCase
{
    public function testDefaultsRelatedRequestIdToNull(): void
    {
        $context = new SendContext();

        self::assertNull($context->relatedRequestId);
    }

    public function testCarriesProvidedRelatedRequestId(): void
    {
        $id = new RequestId(id: 42);
        $context = new SendContext(relatedRequestId: $id);

        self::assertSame($id, $context->relatedRequestId);
    }
}
