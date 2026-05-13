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
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Handler\RequestHandlerRegistry;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestHandlerRegistry::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestHandlerRegistryTest extends TestCase
{
    public function testHasReportsRegisteredAndUnregisteredMethods(): void
    {
        $registry = new RequestHandlerRegistry([
            PingRequest::method() => new PingRequestHandler(),
        ]);

        self::assertTrue($registry->has(PingRequest::method()));
        self::assertFalse($registry->has('vendor/unknown'));
    }

    public function testGetReturnsRegisteredHandler(): void
    {
        $handler = new PingRequestHandler();
        $registry = new RequestHandlerRegistry([
            PingRequest::method() => $handler,
        ]);

        self::assertSame($handler, $registry->get(PingRequest::method()));
    }

    public function testGetThrowsMethodNotFoundExceptionForUnregisteredMethod(): void
    {
        $registry = new RequestHandlerRegistry([]);

        try {
            $registry->get('vendor/unknown');
            self::fail('Expected MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertSame('vendor/unknown', $e->method);
            self::assertSame('No class registered for method "vendor/unknown".', $e->getMessage());
        }
    }

    public function testMethodsReturnsRegisteredKeysInInsertionOrder(): void
    {
        $registry = new RequestHandlerRegistry([
            'b/method' => new PingRequestHandler(),
            'a/method' => new PingRequestHandler(),
        ]);

        self::assertSame(['b/method', 'a/method'], $registry->methods());
    }

    public function testMethodsReturnsEmptyListForEmptyRegistry(): void
    {
        $registry = new RequestHandlerRegistry([]);

        self::assertSame([], $registry->methods());
    }

    public function testConstructorRejectsEmptyStringKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Request handler registry key must be a non-empty string.');

        // @phpstan-ignore argument.type
        new RequestHandlerRegistry(['' => new PingRequestHandler()]);
    }

    public function testConstructorRejectsValueNotImplementingHandlerInterface(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Request handler registry value must implement RequestHandlerInterface.');

        // @phpstan-ignore argument.type
        new RequestHandlerRegistry([PingRequest::method() => new \stdClass()]);
    }
}
