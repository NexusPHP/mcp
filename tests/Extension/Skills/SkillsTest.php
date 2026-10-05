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

namespace Nexus\Mcp\Tests\Extension\Skills;

use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Skills::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillsTest extends AbstractMcpTestCase
{
    public function testPinsTheProtocolVocabulary(): void
    {
        self::assertSame(
            [
                'IDENTIFIER' => 'io.modelcontextprotocol/skills',
                'URI_PREFIX' => 'skill://',
                'MANIFEST_FILENAME' => 'SKILL.md',
                'MANIFEST_MIME_TYPE' => 'text/markdown',
                'DIRECTORY_MIME_TYPE' => 'inode/directory',
                'DIRECTORY_READ_SETTING' => 'directoryRead',
                'DYNAMIC_RESOURCES' => 'dynamic',
                'MAX_RESOURCES_PER_SKILL' => 512,
                'MAX_BYTES_PER_SKILL' => 16_777_216,
            ],
            (new \ReflectionClass(Skills::class))->getConstants(),
        );
    }
}
