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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery;

use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;

/**
 * Source object carrying its server identity through a class-level `#[AsServer]`.
 */
#[AsServer(
    name: 'described-server',
    version: '2.3.4',
    title: 'Described Server',
    description: 'A server described entirely by attributes.',
    websiteUrl: 'https://nexus.test',
    instructions: 'Call the tools politely.',
    icons: [new Icon(src: 'https://nexus.test/icon.svg')],
)]
final class SelfDescribingServer
{
    #[AsTool(description: 'Repeats a message.')]
    public function repeat(string $message): string
    {
        return $message;
    }
}
