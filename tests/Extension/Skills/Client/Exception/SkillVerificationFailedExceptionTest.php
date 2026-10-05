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

namespace Nexus\Mcp\Tests\Extension\Skills\Client\Exception;

use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillVerificationFailedException::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillVerificationFailedExceptionTest extends AbstractMcpTestCase
{
    public function testCarriesTheUriAndTheReasonInItsMessage(): void
    {
        $exception = new SkillVerificationFailedException('skill://refunds/SKILL.md', 'does not match the digest listed in the manifest');

        self::assertSame(
            'Skill file "skill://refunds/SKILL.md" failed verification: it does not match the digest listed in the manifest.',
            $exception->getMessage(),
        );
    }

    public function testBoundsAndEscapesAHostileUri(): void
    {
        $exception = new SkillVerificationFailedException('skill://'.str_repeat('u', 300)."\x1b", 'is not listed in the manifest of the skill');

        self::assertSame(
            \sprintf('Skill file "skill://%s..." failed verification: it is not listed in the manifest of the skill.', str_repeat('u', 245)),
            $exception->getMessage(),
        );
    }
}
