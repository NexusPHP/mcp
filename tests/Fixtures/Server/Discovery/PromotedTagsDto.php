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

final readonly class PromotedTagsDto
{
    public string $heading;

    /**
     * @param list<int> $widths
     */
    public function __construct(
        /**
         * @var list<string> The tags to apply.
         */
        public array $tags,
        /**
         * @var list<BackedStringEnum>
         */
        public array $colors = [],
        public int $plain = 0,
        string $label = '',
        /**
         * @var list<string>
         */
        public array $widths = [],
        /**
         * @var list<float>
         */
        public array $ratios = [],
    ) {
        $this->heading = strtoupper($label);
    }
}
