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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery\Names\First {
    use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;

    final class FirstScope
    {
        public function declare(): string
        {
            return BackedIntEnum::class;
        }
    }
}

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery\Names\Second {
    use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;

    final class SecondScope
    {
        public function declare(): string
        {
            return BackedStringEnum::class;
        }
    }
}
