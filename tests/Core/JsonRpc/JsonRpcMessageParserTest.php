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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\Exception\JsonRpcParserException;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestPingOverride;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonRpcMessageParser::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcMessageParserTest extends TestCase
{
    public function testPingRequestSerializesWithoutEmptyParams(): void
    {
        $request = new PingRequest(new RequestId(42));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'ping'],
            $request->toArray(),
        );
    }

    public function testPingRequestRoundTripUsesDefaultRegistration(): void
    {
        $parser = new JsonRpcMessageParser();

        $original = new PingRequest(new RequestId(42));
        $parsed = $parser->parse($original->toArray());

        if (! $parsed instanceof PingRequest) {
            self::fail(\sprintf('Expected PingRequest, got %s.', $parsed::class));
        }

        self::assertSame(42, $parsed->id->id);
        self::assertNull($parsed->params->meta);
    }

    public function testUserRequestsOverrideDefaults(): void
    {
        $parser = new JsonRpcMessageParser(requests: ['ping' => TestPingOverride::class]);

        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'ping',
        ]);

        self::assertInstanceOf(TestPingOverride::class, $parsed);
    }

    public function testPingRequestWithProgressTokenRoundTrip(): void
    {
        $parser = new JsonRpcMessageParser();

        $original = new PingRequest(
            new RequestId('req-1'),
            new EmptyRequestParams(new RequestMeta(new ProgressToken('tok-1'), ['vendor' => 'x'])),
        );

        $wire = $original->toArray();

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'method' => 'ping',
                'params' => ['_meta' => ['vendor' => 'x', 'progressToken' => 'tok-1']],
            ],
            $wire,
        );

        $parsed = $parser->parse($wire);

        if (! $parsed instanceof PingRequest) {
            self::fail(\sprintf('Expected PingRequest, got %s.', $parsed::class));
        }

        $meta = $parsed->params->meta;

        if (null === $meta) {
            self::fail('Expected request meta to be set.');
        }

        $progressToken = $meta->progressToken;

        if (null === $progressToken) {
            self::fail('Expected progressToken to be set.');
        }

        self::assertSame('req-1', $parsed->id->id);
        self::assertSame('tok-1', $progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testEmptyResultRoundTrip(): void
    {
        $parser = new JsonRpcMessageParser();

        $response = new JsonRpcResultResponse(new RequestId(42), new EmptyResult());

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => []],
            $response->toArray(),
        );

        $parsed = $parser->parse($response->toArray(), EmptyResult::class);

        if (! $parsed instanceof JsonRpcResultResponse) {
            self::fail(\sprintf('Expected JsonRpcResultResponse, got %s.', $parsed::class));
        }

        self::assertInstanceOf(EmptyResult::class, $parsed->result);
        self::assertSame(42, $parsed->id->id);
    }

    public function testParseReturnsErrorResponseWhenErrorKeyPresent(): void
    {
        $parser = new JsonRpcMessageParser();

        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'boom'],
        ]);

        self::assertInstanceOf(JsonRpcErrorResponse::class, $parsed);
        self::assertNotNull($parsed->id);
        self::assertSame(1, $parsed->id->id);
        self::assertSame('boom', $parsed->error->message);
    }

    public function testParseWrapsErrorResponseFailure(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessageMatches('/^Invalid error response: .+/');

        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'error' => ['code' => 1]]);
    }

    public function testParseDispatchesDefaultNotificationFromRegistry(): void
    {
        $parser = new JsonRpcMessageParser();

        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        self::assertInstanceOf(InitializedNotification::class, $parsed);
    }

    public function testParseDispatchesNotificationWithParams(): void
    {
        $parser = new JsonRpcMessageParser(notifications: ['tests/test-notification' => TestNotification::class]);

        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'tests/test-notification',
            'params' => ['_meta' => ['vendor' => 'x']],
        ]);

        if (! $parsed instanceof TestNotification) {
            self::fail(\sprintf('Expected TestNotification, got %s.', $parsed::class));
        }

        self::assertNotNull($parsed->params->meta);
        self::assertSame(['vendor' => 'x'], $parsed->params->meta->extras);
    }

    public function testParseDispatchesNotificationWithoutParams(): void
    {
        $parser = new JsonRpcMessageParser(notifications: ['tests/test-notification' => TestNotification::class]);

        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'tests/test-notification',
        ]);

        self::assertInstanceOf(TestNotification::class, $parsed);
    }

    public function testParseWrapsNotificationFromArrayFailure(): void
    {
        $parser = new JsonRpcMessageParser(notifications: ['tests/test-notification' => TestNotification::class]);

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessageMatches('#^Invalid "tests/test-notification" notification: .+#');

        $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'tests/test-notification',
            'params' => 'bad',
        ]);
    }

    public function testParseRejectsUnknownMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('No request class registered for method "tools/list".');

        $parser->parse([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);
    }

    public function testParseRejectsUnknownNotificationMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('No notification class registered for method "notifications/__test_only__".');

        $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'notifications/__test_only__',
        ]);
    }

    public function testParseRejectsMissingMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Wire message must carry a "method"');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1]);
    }

    public function testParseRejectsNonStringMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Wire "method" must be a non-empty string, int given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42]);
    }

    public function testParseRejectsEmptyStringMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Wire "method" must be a non-empty string, string given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => '']);
    }

    public function testParseWrapsRequestFromArrayFailure(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessageMatches('/^Invalid "ping" request: PingRequest wire "id" must be int or string/');

        $parser->parse(['jsonrpc' => '2.0', 'id' => ['bad'], 'method' => 'ping']);
    }

    public function testParseRejectsWrongVersion(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC version');

        $parser->parse(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']);
    }

    public function testParseRejectsMissingVersion(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC version: expected "2.0", got NULL');

        $parser->parse(['id' => 1, 'method' => 'ping']);
    }

    public function testParseRequiresResultClassWhenWireCarriesResult(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Success response requires the expected Result class');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
    }

    public function testParseRejectsResultResponseWithMissingId(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Success response must carry an "id".');

        $parser->parse(['jsonrpc' => '2.0', 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithBadIdType(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Response "id" must be int or string, array given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithNonObjectResult(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessage('Success response "result" must be an object, string given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'bad'], EmptyResult::class);
    }

    public function testParseWrapsResultResponseFromArrayFailure(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(JsonRpcParserException::class);
        $this->expectExceptionMessageMatches('/^Invalid .+EmptyResult payload: Result "_meta" must be an object/');

        $parser->parse(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['_meta' => 'bad']],
            EmptyResult::class,
        );
    }
}
