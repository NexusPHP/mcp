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
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingNotificationHandler;
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
        $handler = new PingRequestHandler();
        $registry = new HandlerRegistry(
            [PingRequest::getMethod() => $handler],
            RequestHandlerInterface::class,
            'Request handler',
        );

        self::assertSame($handler, $registry->get(PingRequest::getMethod()));
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
            ['' => new PingRequestHandler()],
            RequestHandlerInterface::class,
            'Request handler',
        );
    }

    public function testConstructorRejectsValueNotImplementingHandlerInterface(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Request handler registry value must implement .+RequestHandlerInterface\'\\.$/');

        new HandlerRegistry(
            [PingRequest::getMethod() => new \stdClass()],
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
            ['' => new RecordingNotificationHandler()],
            NotificationHandlerInterface::class,
            'Notification handler',
        );
    }

    public function testNotificationHandlerBindingRejectsNonHandlerValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Notification handler registry value must implement .+NotificationHandlerInterface\'\\.$/');

        new HandlerRegistry(
            [InitializedNotification::getMethod() => new \stdClass()],
            NotificationHandlerInterface::class,
            'Notification handler',
        );
    }
}
