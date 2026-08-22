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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\CallLog;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingMiddleware;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @internal
 */
#[CoversClass(MiddlewarePipeline::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class MiddlewarePipelineTest extends AbstractMcpTestCase
{
    public function testEmptyPipelineDelegatesToTheInnerHandler(): void
    {
        $handler = $this->handler();

        $response = (new MiddlewarePipeline($handler))->handle($this->request());

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRunsMiddlewareThenDelegatesToTheInnerHandler(): void
    {
        $log = new CallLog();
        $handler = $this->handler();

        $response = (new MiddlewarePipeline($handler, new RecordingMiddleware('a', $log)))
            ->handle($this->request())
        ;

        self::assertSame(['a'], $log->labels);
        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRunsMiddlewareOutermostFirst(): void
    {
        $log = new CallLog();
        $handler = $this->handler();

        (new MiddlewarePipeline(
            $handler,
            new RecordingMiddleware('a', $log),
            new RecordingMiddleware('b', $log),
        ))->handle($this->request());

        self::assertSame(['a', 'b'], $log->labels);
        self::assertTrue($handler->called);
    }

    public function testShortCircuitingMiddlewareHaltsTheChain(): void
    {
        $log = new CallLog();
        $handler = $this->handler();
        $preset = (new Psr17Factory())->createResponse(418);
        $shortCircuit = self::createStub(MiddlewareInterface::class);
        $shortCircuit->method('process')->willReturn($preset);

        $response = (new MiddlewarePipeline(
            $handler,
            $shortCircuit,
            new RecordingMiddleware('never', $log),
        ))->handle($this->request());

        self::assertSame(418, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame([], $log->labels);
    }

    public function testAcceptsMiddlewareGivenAsNamedArguments(): void
    {
        $log = new CallLog();
        $handler = $this->handler();

        (new MiddlewarePipeline(
            $handler,
            outer: new RecordingMiddleware('a', $log),
            inner: new RecordingMiddleware('b', $log),
        ))->handle($this->request());

        self::assertSame(['a', 'b'], $log->labels);
        self::assertTrue($handler->called);
    }

    public function testIsReentrantAcrossCalls(): void
    {
        $log = new CallLog();
        $handler = $this->handler();
        $pipeline = new MiddlewarePipeline($handler, new RecordingMiddleware('a', $log));

        $pipeline->handle($this->request());
        $pipeline->handle($this->request());

        self::assertSame(['a', 'a'], $log->labels);
    }

    private function handler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/');
    }
}
