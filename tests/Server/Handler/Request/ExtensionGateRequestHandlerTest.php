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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Server\Handler\Request\ExtensionGateRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ExtensionGateRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ExtensionGateRequestHandlerTest extends AbstractMcpTestCase
{
    public function testADeclaredExtensionReachesTheHandler(): void
    {
        $marker = new EmptyResult();
        $handler = new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => $marker,
        ));

        $result = $handler->handle(
            TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]),
            $this->makeContext(new ClientCapabilities(extensions: ['com.example/feature' => []])),
        );

        self::assertSame($marker, $result);
    }

    public function testADeclarationWithSettingsAlsoPasses(): void
    {
        $marker = new EmptyResult();
        $handler = new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => $marker,
        ));

        $result = $handler->handle(
            TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]),
            $this->makeContext(new ClientCapabilities(extensions: ['com.example/feature' => ['mode' => 'fast']])),
        );

        self::assertSame($marker, $result);
    }

    public function testAnUndeclaredExtensionIsRejected(): void
    {
        $handler = new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => throw new \RuntimeException('The gate must reject before the handler runs.'),
        ));

        $this->expectException(MissingRequiredClientCapabilityException::class);
        $this->expectExceptionMessageIs('This request requires client capabilities the client did not declare: extensions.com.example/feature.');

        $handler->handle(
            TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]),
            $this->makeContext(new ClientCapabilities(extensions: ['com.example/other' => []])),
        );
    }

    public function testAnAbsentExtensionsSlotIsRejected(): void
    {
        $handler = new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => throw new \RuntimeException('The gate must reject before the handler runs.'),
        ));

        $this->expectException(MissingRequiredClientCapabilityException::class);
        $this->expectExceptionMessageIs('This request requires client capabilities the client did not declare: extensions.com.example/feature.');

        $handler->handle(
            TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]),
            $this->makeContext(new ClientCapabilities()),
        );
    }

    public function testTheRejectionCarriesTheRequiredCapabilityAndTheRequestId(): void
    {
        $handler = new ExtensionGateRequestHandler('com.example/feature', new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));

        try {
            $handler->handle(
                TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]),
                $this->makeContext(new ClientCapabilities()),
            );
            self::fail('An undeclared extension must be rejected.');
        } catch (MissingRequiredClientCapabilityException $e) {
            self::assertSame(['extensions' => ['com.example/feature' => []]], $e->requiredCapabilities->toArray());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    private function makeContext(ClientCapabilities $clientCapabilities): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(clientCapabilities: $clientCapabilities),
            new RecordingSender(),
        );
    }
}
