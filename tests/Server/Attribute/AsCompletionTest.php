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

namespace Nexus\Mcp\Tests\Server\Attribute;

use Nexus\Mcp\Server\Attribute\AsCompletion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsCompletion::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsCompletionTest extends TestCase
{
    public function testStoresAPromptCompletion(): void
    {
        $completion = new AsCompletion(argument: 'tone', prompt: 'compose');

        self::assertSame('tone', $completion->argument);
        self::assertSame('compose', $completion->prompt);
        self::assertNull($completion->uriTemplate);
    }

    public function testStoresATemplateCompletion(): void
    {
        $completion = new AsCompletion(argument: 'path', uriTemplate: 'file:///{path}');

        self::assertSame('path', $completion->argument);
        self::assertNull($completion->prompt);
        self::assertSame('file:///{path}', $completion->uriTemplate);
    }
}
