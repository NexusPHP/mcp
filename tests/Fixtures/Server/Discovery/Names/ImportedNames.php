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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery\Names;

use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum as Colour;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Coordinate as Point;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;

final class ImportedNames
{
    use NamesTrait;

    /**
     * @return list<string>
     */
    public function resolve(): array
    {
        return [BackedIntEnum::class, Colour::class, PureEnum::class, Point::class];
    }
}
