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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\InitializeRequestParams;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\Handler\Request\InitializeRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializeRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InitializeRequestHandlerTest extends TestCase
{
    public function testReturnsServerInfoCapabilitiesAndInstructions(): void
    {
        $serverInfo = new Implementation('test-server', '1.0.0');
        $capabilities = new ServerCapabilities(tools: ['listChanged' => true]);
        $handler = new InitializeRequestHandler($serverInfo, $capabilities, 'hello');

        $request = self::makeRequest();
        $context = self::makeContext();

        $result = $handler->handle($request, $context);

        $expected = new InitializeResult(
            ProtocolVersion::latest(),
            $capabilities,
            $serverInfo,
            'hello',
        );
        self::assertSame($expected->toArray(), $result->toArray());
    }

    public function testOmitsInstructionsWhenNotProvided(): void
    {
        $serverInfo = new Implementation('test-server', '1.0.0');
        $capabilities = new ServerCapabilities();
        $handler = new InitializeRequestHandler($serverInfo, $capabilities);

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertArrayNotHasKey('instructions', $result->toArray());
        self::assertNull($result->instructions);
    }

    public function testPropagatesMetaWhenProvided(): void
    {
        $serverInfo = new Implementation('test-server', '1.0.0');
        $capabilities = new ServerCapabilities();
        $meta = new MetaObject(['trace' => 'abc']);
        $handler = new InitializeRequestHandler($serverInfo, $capabilities, null, $meta);

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertSame(['trace' => 'abc'], $result->toArray()['_meta'] ?? null);
    }

    private static function makeRequest(): InitializeRequest
    {
        return new InitializeRequest(
            new RequestId(1),
            new InitializeRequestParams(
                ProtocolVersion::latest(),
                new ClientCapabilities(),
                new Implementation('test-client', '0.0.1'),
            ),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            null,
            null,
            new RecordingSender(),
        );
    }
}
