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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\InvalidRequestException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\JsonRpc\UnparsedResultEnvelope;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
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
        self::assertSame([], $parsed->params->meta->toArray());
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
            new EmptyRequestParams(new RequestMetaObject(new ProgressToken('tok-1'), ['vendor' => 'x'])),
        );

        $envelope = $original->toArray();

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'method' => 'ping',
                'params' => ['_meta' => ['vendor' => 'x', 'progressToken' => 'tok-1']],
            ],
            $envelope,
        );

        $parsed = $parser->parse($envelope);

        if (! $parsed instanceof PingRequest) {
            self::fail(\sprintf('Expected PingRequest, got %s.', $parsed::class));
        }

        $progressToken = $parsed->params->meta->progressToken;

        if (null === $progressToken) {
            self::fail('Expected progressToken to be set.');
        }

        self::assertSame('req-1', $parsed->id->id);
        self::assertSame('tok-1', $progressToken->token);
        self::assertSame(['vendor' => 'x'], $parsed->params->meta->extras);
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

    public function testParseWrapsErrorResponseFailureAsInvalidRequest(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'error' => ['code' => 1]]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'A non-scalar id cannot be preserved on the exception.');
            self::assertSame(ProtocolErrorCode::InvalidRequest, InvalidRequestException::errorCode());
            self::assertMatchesRegularExpression('/^Invalid error response: .+/', $e->getMessage());
        }
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
        }        self::assertSame(['vendor' => 'x'], $parsed->params->meta->extras);
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

    public function testParseWrapsNotificationFromArrayFailureAsInvalidParams(): void
    {
        $parser = new JsonRpcMessageParser(notifications: ['tests/test-notification' => TestNotification::class]);

        try {
            $parser->parse([
                'jsonrpc' => '2.0',
                'method' => 'tests/test-notification',
                'params' => 'bad',
            ]);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertNull($e->requestId, 'Notifications carry no id.');
            self::assertSame(ProtocolErrorCode::InvalidParams, InvalidParamsException::errorCode());
            self::assertMatchesRegularExpression(
                '#^Invalid "tests/test-notification" notification: .+#',
                $e->getMessage(),
            );
        }
    }

    public function testParseRejectsUnknownRequestMethodAsMethodNotFound(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse([
                'jsonrpc' => '2.0',
                'id' => 9,
                'method' => 'vendor/unknown',
            ]);
            self::fail('Expected MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertSame(9, $e->requestId?->id);
            self::assertSame(ProtocolErrorCode::MethodNotFound, MethodNotFoundException::errorCode());
            self::assertSame('No registration found for method "vendor/unknown".', $e->getMessage());
        }
    }

    public function testParseRejectsUnknownNotificationMethodAsMethodNotFound(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse([
                'jsonrpc' => '2.0',
                'method' => 'notifications/__test_only__',
            ]);
            self::fail('Expected MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertNull($e->requestId, 'Notifications carry no id.');
            self::assertSame('No registration found for method "notifications/__test_only__".', $e->getMessage());
        }
    }

    public function testParseRejectsMissingMethodAsInvalidRequest(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('JSON-RPC envelope must carry a "method"', $e->getMessage());
        }
    }

    public function testParseRejectsNonStringMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('JSON-RPC envelope "method" must be a non-empty string, int given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42]);
    }

    public function testParseRejectsEmptyStringMethod(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('JSON-RPC envelope "method" must be a non-empty string, string given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => '']);
    }

    public function testParseWrapsRequestFromArrayFailureAsInvalidParams(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => ['bad'], 'method' => 'ping']);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertNull($e->requestId, 'A non-scalar id cannot be preserved on the exception.');
            self::assertStringStartsWith('Invalid "ping" request: PingRequest "id" must be int or string', $e->getMessage());
        }
    }

    public function testParseRejectsWrongVersionPreservesId(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('Invalid JSON-RPC version', $e->getMessage());
        }
    }

    public function testParseRejectsMissingVersion(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC version: expected "2.0", got null.');

        $parser->parse(['id' => 1, 'method' => 'ping']);
    }

    public function testParseReturnsUnparsedResultEnvelopeWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();

        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 'req-1', 'result' => ['answer' => 42]]);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);

        if (null === $parsed->id) {
            self::fail('Expected non-null id.');
        }

        self::assertSame('req-1', $parsed->id->id);
        self::assertSame(['answer' => 42], $parsed->result);
    }

    public function testParseReturnsUnparsedResultEnvelopeForEmptyResultWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();

        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 7, 'result' => []]);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);

        if (null === $parsed->id) {
            self::fail('Expected non-null id.');
        }

        self::assertSame(7, $parsed->id->id);
        self::assertSame([], $parsed->result);
    }

    public function testParseRejectsMissingIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Success response must carry an "id".');

        $parser->parse(['jsonrpc' => '2.0', 'result' => ['payload' => 'x']]);
    }

    public function testParseRejectsNonScalarIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Response "id" must be int or string, array given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []]);
    }

    public function testParseToleratesNonMapResultWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();

        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 9, 'result' => 'opaque-string']);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);
        self::assertSame(9, $parsed->id?->id);
        self::assertSame('opaque-string', $parsed->result);
    }

    public function testParseRejectsResultResponseWithMissingId(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Success response must carry an "id".');

        $parser->parse(['jsonrpc' => '2.0', 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithBadIdType(): void
    {
        $parser = new JsonRpcMessageParser();

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Response "id" must be int or string, array given.');

        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithNonObjectResult(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'bad'], EmptyResult::class);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('Success response "result" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseWrapsResultResponseFromArrayFailure(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['_meta' => 'bad']],
                EmptyResult::class,
            );
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertMatchesRegularExpression('/^Invalid .+EmptyResult payload: Result "_meta" must be an object/', $e->getMessage());
        }
    }

    public function testEveryParserFailureIsProtocolException(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown/method']);
            self::fail('Expected an AbstractJsonRpcProtocolException.');
        } catch (AbstractJsonRpcProtocolException $e) {
            self::assertSame(ProtocolErrorCode::MethodNotFound, $e::errorCode());
            self::assertSame(1, $e->requestId?->id);
        }
    }

    public function testParseDropsRequestIdWhenEnvelopeIdIsEmptyString(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => '', 'method' => 'unknown/method']);
            self::fail('Expected a MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertNull($e->requestId, 'An empty-string envelope id cannot be wrapped into a RequestId, so the exception carries null.');
        }
    }
}
