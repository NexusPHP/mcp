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

namespace Nexus\Mcp\Tests\Extension\Apps;

use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Apps::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class AppsTest extends AbstractMcpTestCase
{
    public function testPinsTheProtocolVocabulary(): void
    {
        self::assertSame([
            'IDENTIFIER' => 'io.modelcontextprotocol/ui',
            'MIME_TYPE' => 'text/html;profile=mcp-app',
            'URI_PREFIX' => 'ui://',
            'META_KEY' => 'ui',
            'DEPRECATED_RESOURCE_URI_KEY' => 'ui/resourceUri',
        ], (new \ReflectionClass(Apps::class))->getConstants());
    }
}
