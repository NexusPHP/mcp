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
use Nexus\Mcp\Server\Completion\ResourceTemplateCompletionEntry;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceTemplateCompletionEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceTemplateCompletionEntryTest extends AbstractMcpTestCase
{
    public function testStoresItsFields(): void
    {
        $provider = new ClosureCompletionProvider(static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));

        $entry = new ResourceTemplateCompletionEntry('file:///{path}', 'path', $provider);

        self::assertSame('file:///{path}', $entry->uriTemplate);
        self::assertSame('path', $entry->argument);
        self::assertSame($provider, $entry->provider);
    }
}
