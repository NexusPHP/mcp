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

namespace Nexus\Mcp\Tests\Server\Completion;

use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\ClosureCompletionProvider;
use Nexus\Mcp\Server\Completion\PromptCompletionEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptCompletionEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class PromptCompletionEntryTest extends TestCase
{
    public function testStoresItsFields(): void
    {
        $provider = new ClosureCompletionProvider(static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));

        $entry = new PromptCompletionEntry('compose', 'tone', $provider);

        self::assertSame('compose', $entry->prompt);
        self::assertSame('tone', $entry->argument);
        self::assertSame($provider, $entry->provider);
    }
}
