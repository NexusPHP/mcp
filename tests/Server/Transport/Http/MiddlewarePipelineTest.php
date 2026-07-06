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

namespace Nexus\Mcp\Tests\Server\Transport\Http;

use Nexus\Mcp\Server\Transport\Http\MiddlewarePipeline;
use Nexus\Mcp\Tests\Fixtures\Server\Http\CallLog;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingMiddleware;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Server\Http\ShortCircuitMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(MiddlewarePipeline::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class MiddlewarePipelineTest extends TestCase
{
    public function testEmptyPipelineDelegatesToTheInnerHandler(): void
    {
        $handler = self::handler();

        $response = new MiddlewarePipeline($handler)->handle(self::request());

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRunsMiddlewareThenDelegatesToTheInnerHandler(): void
    {
        $log = new CallLog();
        $handler = self::handler();

        $response = new MiddlewarePipeline($handler, new RecordingMiddleware('a', $log))
            ->handle(self::request())
        ;

        self::assertSame(['a'], $log->labels);
        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRunsMiddlewareOutermostFirst(): void
    {
        $log = new CallLog();
        $handler = self::handler();

        new MiddlewarePipeline(
            $handler,
            new RecordingMiddleware('a', $log),
            new RecordingMiddleware('b', $log),
        )->handle(self::request());

        self::assertSame(['a', 'b'], $log->labels);
        self::assertTrue($handler->called);
    }

    public function testShortCircuitingMiddlewareHaltsTheChain(): void
    {
        $log = new CallLog();
        $handler = self::handler();
        $preset = new Psr17Factory()->createResponse(418);

        $response = new MiddlewarePipeline(
            $handler,
            new ShortCircuitMiddleware($preset),
            new RecordingMiddleware('never', $log),
        )->handle(self::request());

        self::assertSame(418, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame([], $log->labels);
    }

    public function testIsReentrantAcrossCalls(): void
    {
        $log = new CallLog();
        $handler = self::handler();
        $pipeline = new MiddlewarePipeline($handler, new RecordingMiddleware('a', $log));

        $pipeline->handle(self::request());
        $pipeline->handle(self::request());

        self::assertSame(['a', 'a'], $log->labels);
    }

    private static function handler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler(new Psr17Factory()->createResponse(200));
    }

    private static function request(): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('POST', 'https://mcp.test/');
    }
}
