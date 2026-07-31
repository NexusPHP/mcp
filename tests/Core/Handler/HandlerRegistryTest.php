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

namespace Nexus\Mcp\Tests\Core\Handler;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HandlerRegistry::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class HandlerRegistryTest extends TestCase
{
    public function testGetReturnsRegisteredHandler(): void
    {
        $handler = new ClosureRequestHandler(static fn(): Result => self::fail('handler must not run'));
        $registry = new HandlerRegistry(
            [DiscoverRequest::getMethod() => $handler],
            RequestHandlerInterface::class,
            'Request handler',
        );

        self::assertSame($handler, $registry->get(DiscoverRequest::getMethod()));
    }

    public function testGetReturnsNullForUnregisteredMethod(): void
    {
        $registry = new HandlerRegistry([], RequestHandlerInterface::class, 'Request handler');

        self::assertNull($registry->get('vendor/unknown'));
    }

    public function testConstructorRejectsEmptyStringKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Request handler registry key must be a non-empty string.');

        new HandlerRegistry(
            // @phpstan-ignore argument.type
            ['' => new ClosureRequestHandler(static fn(): Result => self::fail('handler must not run'))],
            RequestHandlerInterface::class,
            'Request handler',
        );
    }

    public function testConstructorRejectsValueNotImplementingHandlerInterface(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Request handler registry value must implement .+RequestHandlerInterface\'\\.$/');

        new HandlerRegistry(
            [DiscoverRequest::getMethod() => new \stdClass()],
            RequestHandlerInterface::class,
            'Request handler',
        );
    }

    public function testLabelFlowsThroughToAssertionMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Notification handler registry key must be a non-empty string.');

        new HandlerRegistry(
            // @phpstan-ignore argument.type
            ['' => self::createStub(NotificationHandlerInterface::class)],
            NotificationHandlerInterface::class,
            'Notification handler',
        );
    }

    public function testNotificationHandlerBindingRejectsNonHandlerValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Notification handler registry value must implement .+NotificationHandlerInterface\'\\.$/');

        new HandlerRegistry(
            [ToolListChangedNotification::getMethod() => new \stdClass()],
            NotificationHandlerInterface::class,
            'Notification handler',
        );
    }
}
