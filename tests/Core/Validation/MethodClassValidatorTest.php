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

use Nexus\Mcp\Core\Validation\MethodClassValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MethodClassValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MethodClassValidatorTest extends AbstractMcpTestCase
{
    public function testAClassDeclaringItsMethodPasses(): void
    {
        $this->expectNotToPerformAssertions();

        MethodClassValidator::validate(TestRequest::class, TestRequest::getMethod());
        MethodClassValidator::validate(TestNotification::class, TestNotification::getMethod(), isNotification: true);
    }

    public function testARequestClassDeclaringADifferentMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Request class "Nexus\Mcp\Tests\Fixtures\Core\TestRequest" must declare the method "acme/lookup" it is registered for, \'tests/test-request\' declared.',
        );

        MethodClassValidator::validate(TestRequest::class, 'acme/lookup');
    }

    public function testANotificationClassDeclaringADifferentMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Notification class "Nexus\Mcp\Tests\Fixtures\Core\TestNotification" must declare the method "acme/ping" it is registered for, \'tests/test-notification\' declared.',
        );

        MethodClassValidator::validate(TestNotification::class, 'acme/ping', isNotification: true);
    }
}
