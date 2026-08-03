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

namespace Nexus\Mcp\Tests\Server\Auth;

use Nexus\Mcp\Server\Auth\JwksAccessTokenValidator;
use Nexus\Mcp\Server\Auth\SuggestedDependencyGuard;
use Nexus\Mcp\Server\Exception\MissingSuggestedDependencyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SuggestedDependencyGuard::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SuggestedDependencyGuardTest extends TestCase
{
    public function testAnInstalledPackagePassesSilently(): void
    {
        $this->expectNotToPerformAssertions();

        SuggestedDependencyGuard::verify(JwksAccessTokenValidator::class, \stdClass::class, 'acme/jwt', '^1.0');
    }

    public function testAMissingPackageNamesTheConsumerAndTheInstallCommand(): void
    {
        $this->expectException(MissingSuggestedDependencyException::class);
        $this->expectExceptionMessageIs(
            'Nexus\Mcp\Server\Auth\JwksAccessTokenValidator requires the suggested "acme/jwt" package. Install it with "composer require acme/jwt:^1.0".',
        );

        SuggestedDependencyGuard::verify(JwksAccessTokenValidator::class, 'Acme\Jwt\DoesNotExist', 'acme/jwt', '^1.0');
    }
}
