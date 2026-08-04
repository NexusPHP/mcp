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

namespace Nexus\Mcp\Tests\Client\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Client\ClientContext;
use Nexus\Mcp\Client\Dispatch\DiscoveredServerCapabilities;
use Nexus\Mcp\Client\Handler\Request\ExtensionGateRequestHandler;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExtensionGateRequestHandler::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ExtensionGateRequestHandlerTest extends TestCase
{
    public function testServesBeforeDiscovery(): void
    {
        $marker = new EmptyResult();
        $handler = self::buildGate(new DiscoveredServerCapabilities(), $marker);

        $result = $handler->handle(new TestRequest(new RequestId(id: 7)), self::makeContext());

        self::assertSame($marker, $result);
    }

    public function testServesWhenTheServerAdvertisedTheExtension(): void
    {
        $marker = new EmptyResult();
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(extensions: ['com.example/feature' => []]));
        $handler = self::buildGate($discovered, $marker);

        $result = $handler->handle(new TestRequest(new RequestId(id: 7)), self::makeContext());

        self::assertSame($marker, $result);
    }

    public function testRejectsWhenTheServerDidNotAdvertiseTheExtension(): void
    {
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(extensions: ['com.example/other' => []]));
        $handler = self::buildGate($discovered, new EmptyResult());

        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessageIs('No registration found for method "tests/test-request".');

        $handler->handle(new TestRequest(new RequestId(id: 7)), self::makeContext());
    }

    public function testRejectsWhenTheServerAdvertisedNoExtensionsAtAll(): void
    {
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(tools: []));
        $handler = self::buildGate($discovered, new EmptyResult());

        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessageIs('No registration found for method "tests/test-request".');

        $handler->handle(new TestRequest(new RequestId(id: 7)), self::makeContext());
    }

    private static function buildGate(DiscoveredServerCapabilities $discovered, EmptyResult $marker): ExtensionGateRequestHandler
    {
        return new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => $marker,
        ), $discovered);
    }

    private static function makeContext(): ClientContext
    {
        return new ClientContext(
            new RequestId(id: 7),
            new NullCancellation(),
            null,
            new RecordingSender(),
        );
    }
}
