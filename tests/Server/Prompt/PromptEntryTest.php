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

namespace Nexus\Mcp\Tests\Server\Prompt;

use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class PromptEntryTest extends TestCase
{
    public function testExposesPromptAndRenderer(): void
    {
        $prompt = new Prompt('greeting');
        $renderer = new ClosurePromptRenderer(
            static fn(): GetPromptResult => new GetPromptResult([]),
        );

        $entry = new PromptEntry($prompt, $renderer);

        self::assertSame($prompt, $entry->prompt);
        self::assertSame($renderer, $entry->renderer);
    }
}
