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

namespace Nexus\Mcp\Tests\Core\Validation;

use Nexus\Mcp\Core\Exception\MissingSuggestedDependencyException;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Validation\StubPackageBackedConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SuggestedDependencyGuard::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SuggestedDependencyGuardTest extends AbstractMcpTestCase
{
    public function testAnInstalledPackagePassesSilently(): void
    {
        $this->expectNotToPerformAssertions();

        SuggestedDependencyGuard::verify(StubPackageBackedConsumer::class, \stdClass::class, 'acme/jwt', '^1.0');
    }

    public function testAMissingPackageNamesTheConsumerAndTheInstallCommand(): void
    {
        $this->expectException(MissingSuggestedDependencyException::class);
        $this->expectExceptionMessageIs(
            'Nexus\Mcp\Tests\Fixtures\Core\Validation\StubPackageBackedConsumer requires the suggested "acme/jwt" package. Install it with "composer require acme/jwt:^1.0".',
        );

        SuggestedDependencyGuard::verify(StubPackageBackedConsumer::class, 'Acme\Jwt\DoesNotExist', 'acme/jwt', '^1.0');
    }
}
