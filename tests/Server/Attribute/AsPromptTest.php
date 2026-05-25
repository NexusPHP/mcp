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
use Nexus\Mcp\Server\Attribute\AsPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsPrompt::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsPromptTest extends TestCase
{
    public function testDefaultsToNull(): void
    {
        $prompt = new AsPrompt();

        self::assertNull($prompt->name);
        self::assertNull($prompt->title);
        self::assertNull($prompt->description);
        self::assertNull($prompt->icons);
        self::assertNull($prompt->meta);
    }

    public function testStoresAllValues(): void
    {
        $icon = new Icon('https://example.test/icon.svg');

        $prompt = new AsPrompt(
            name: 'review',
            title: 'Code review',
            description: 'Reviews a diff.',
            icons: [$icon],
            meta: ['vendor' => 'acme'],
        );

        self::assertSame('review', $prompt->name);
        self::assertSame('Code review', $prompt->title);
        self::assertSame('Reviews a diff.', $prompt->description);
        self::assertSame([$icon], $prompt->icons);
        self::assertSame(['vendor' => 'acme'], $prompt->meta);
    }
}
