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

use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AsTool::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsToolTest extends AbstractMcpTestCase
{
    public function testDefaultsToNull(): void
    {
        $tool = new AsTool();

        self::assertNull($tool->name);
        self::assertNull($tool->title);
        self::assertNull($tool->description);
        self::assertNull($tool->annotations);
        self::assertNull($tool->icons);
        self::assertNull($tool->outputSchema);
        self::assertNull($tool->meta);
    }

    public function testStoresAllValues(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: true);
        $icon = new Icon(src: 'https://example.test/icon.svg');

        $tool = new AsTool(
            name: 'calculate',
            title: 'Calculator',
            description: 'Adds two numbers.',
            annotations: $annotations,
            icons: [$icon],
            outputSchema: ['type' => 'object'],
            meta: ['vendor' => 'acme'],
        );

        self::assertSame('calculate', $tool->name);
        self::assertSame('Calculator', $tool->title);
        self::assertSame('Adds two numbers.', $tool->description);
        self::assertSame($annotations, $tool->annotations);
        self::assertSame([$icon], $tool->icons);
        self::assertSame(['type' => 'object'], $tool->outputSchema);
        self::assertSame(['vendor' => 'acme'], $tool->meta);
    }
}
