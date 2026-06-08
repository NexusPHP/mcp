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
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\JsonRpc\UnparsedResultEnvelope;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
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
    public function testRequestWithNullParamsSerializesWithoutParamsKey(): void
    {
        $request = new TestRequest(new RequestId(42));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tests/test-request'],
            $request->toArray(),
        );
    }

    public function testDiscoverRequestRoundTripUsesDefaultRegistration(): void
    {
        $original = new DiscoverRequest(new RequestId(42), new EmptyRequestParams(RequestMetaObjectFactory::create()));

        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse($original->toArray());

        if (! $parsed instanceof DiscoverRequest) {
            self::fail(\sprintf('Expected DiscoverRequest, got %s.', $parsed::class));
        }

        self::assertSame(42, $parsed->id->id);
        self::assertSame(RequestMetaObjectFactory::shape(), $parsed->params->meta->toArray());
    }

    public function testUserRequestsRegisterMethodsAbsentFromDefaults(): void
    {
        $parser = new JsonRpcMessageParser(requests: ['tests/test-request' => TestRequest::class]);
        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tests/test-request',
        ]);

        self::assertInstanceOf(TestRequest::class, $parsed);
    }

    public function testDiscoverRequestWithProgressTokenRoundTrip(): void
    {
        $original = new DiscoverRequest(
            new RequestId('req-1'),
            new EmptyRequestParams(RequestMetaObjectFactory::create(new ProgressToken('tok-1'), ['vendor' => 'x'])),
        );

        $envelope = $original->toArray();

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'method' => 'server/discover',
                'params' => ['_meta' => RequestMetaObjectFactory::shape(new ProgressToken('tok-1'), ['vendor' => 'x'])],
            ],
            $envelope,
        );

        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse($envelope);

        if (! $parsed instanceof DiscoverRequest) {
            self::fail(\sprintf('Expected DiscoverRequest, got %s.', $parsed::class));
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
        $response = new JsonRpcResultResponse(new RequestId(42), new EmptyResult());

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => ['resultType' => 'complete']],
            $response->toArray(),
        );

        $parser = new JsonRpcMessageParser();
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
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'error' => ['code' => 1]]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'A non-scalar id cannot be preserved on the exception.');
            self::assertSame(ProtocolErrorCode::InvalidRequest, InvalidRequestException::getErrorCode());
            self::assertMatchesRegularExpression('/^Invalid error response: .+/', $e->getMessage());
        }
    }

    public function testParseDispatchesDefaultNotificationFromRegistry(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]);

        self::assertInstanceOf(ToolListChangedNotification::class, $parsed);
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
        try {
            $parser = new JsonRpcMessageParser(notifications: ['tests/test-notification' => TestNotification::class]);
            $parser->parse([
                'jsonrpc' => '2.0',
                'method' => 'tests/test-notification',
                'params' => 'bad',
            ]);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertNull($e->requestId, 'Notifications carry no id.');
            self::assertSame(ProtocolErrorCode::InvalidParams, InvalidParamsException::getErrorCode());
            self::assertMatchesRegularExpression(
                '#^Invalid "tests/test-notification" notification: .+#',
                $e->getMessage(),
            );
        }
    }

    public function testParseRejectsUnknownRequestMethodAsMethodNotFound(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse([
                'jsonrpc' => '2.0',
                'id' => 9,
                'method' => 'vendor/unknown',
            ]);
            self::fail('Expected MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertSame(9, $e->requestId?->id);
            self::assertSame(ProtocolErrorCode::MethodNotFound, MethodNotFoundException::getErrorCode());
            self::assertSame('No registration found for method "vendor/unknown".', $e->getMessage());
        }
    }

    public function testParseRejectsUnknownNotificationMethodAsMethodNotFound(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
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

    public function testParseRejectsRequestMethodSentAsNotificationAsMisrouted(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse([
                'jsonrpc' => '2.0',
                'method' => 'server/discover',
            ]);
            self::fail('Expected MethodMisroutedException.');
        } catch (MethodMisroutedException $e) {
            self::assertNull($e->requestId, 'Notification envelopes carry no id.');
            self::assertSame(ProtocolErrorCode::InvalidRequest, MethodMisroutedException::getErrorCode());
            self::assertSame('Method "server/discover" must be sent as a request, not a notification.', $e->getMessage());
        }
    }

    public function testParseRejectsNotificationMethodSentAsRequestAsMisrouted(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse([
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'notifications/tools/list_changed',
            ]);
            self::fail('Expected MethodMisroutedException.');
        } catch (MethodMisroutedException $e) {
            self::assertSame(7, $e->requestId?->id);
            self::assertSame(
                'Method "notifications/tools/list_changed" must be sent as a notification, not a request.',
                $e->getMessage(),
            );
        }
    }

    public function testParseSanitisesAttackerMethodBytesInMethodNotFoundMessage(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => "vendor/unknown\n[CRIT] spoofed",
            ]);
            self::fail('Expected MethodNotFoundException.');
        } catch (MethodNotFoundException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame(
                'No registration found for method "vendor/unknown\\x0a[CRIT] spoofed".',
                $e->getMessage(),
            );
        }
    }

    public function testParseRejectsMissingMethodAsInvalidRequest(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('JSON-RPC envelope must carry a "method"', $e->getMessage());
        }
    }

    public function testParseRejectsNonStringMethod(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('JSON-RPC envelope "method" must be a non-empty string, int given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42]);
    }

    public function testParseRejectsEmptyStringMethod(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('JSON-RPC envelope "method" must be a non-empty string, string given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => '']);
    }

    public function testParseRejectsEmptyStringIdInSuccessEnvelopeAsInvalidRequest(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => '', 'result' => ['answer' => 42]]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'An empty-string id cannot be preserved on the exception.');
            self::assertSame('"id" must be a non-empty string.', $e->getMessage());
        }
    }

    public function testParseRejectsEmptyStringIdWithTypedResultAsInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('"id" must be a non-empty string.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => '', 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsNonScalarRequestIdAsInvalidRequest(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => ['bad'], 'method' => 'tests/test-request']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'A non-scalar id cannot be preserved on the exception.');
            self::assertSame(ProtocolErrorCode::InvalidRequest, InvalidRequestException::getErrorCode());
            self::assertSame('Request "id" must be an int or string, array given.', $e->getMessage());
        }
    }

    public function testParseWrapsRequestParamsFailureAsInvalidParams(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => 'not-an-object']);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame(ProtocolErrorCode::InvalidParams, InvalidParamsException::getErrorCode());
            self::assertSame('Invalid "server/discover" request: "params" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseRejectsWrongVersionPreservesId(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'tests/test-request']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('Invalid JSON-RPC version', $e->getMessage());
        }
    }

    public function testParseRejectsMissingVersion(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid JSON-RPC version: expected "2.0", got null.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['id' => 1, 'method' => 'tests/test-request']);
    }

    public function testParseEscapesControlCharactersInWrongVersionMessage(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => "1.0\ninjected", 'id' => 1, 'method' => 'tests/test-request']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(
                'Invalid JSON-RPC version: expected "2.0", got \'1.0\x0ainjected\'.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    public function testParseReturnsUnparsedResultEnvelopeWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 'req-1', 'result' => ['answer' => 42]]);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);
        self::assertSame('req-1', $parsed->id->id);
        self::assertSame(['answer' => 42], $parsed->result);
    }

    public function testParseReturnsUnparsedResultEnvelopeForEmptyResultWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 7, 'result' => []]);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);
        self::assertSame(7, $parsed->id->id);
        self::assertSame([], $parsed->result);
    }

    public function testParseRejectsMissingIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Success response must carry an "id".');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'result' => ['payload' => 'x']]);
    }

    public function testParseRejectsNonScalarIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Response "id" must be an int or string, array given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []]);
    }

    public function testParseToleratesNonMapResultWhenResultClassOmitted(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(['jsonrpc' => '2.0', 'id' => 9, 'result' => 'opaque-string']);

        self::assertInstanceOf(UnparsedResultEnvelope::class, $parsed);
        self::assertSame(9, $parsed->id->id);
        self::assertSame('opaque-string', $parsed->result);
    }

    public function testParseRejectsResultResponseWithMissingId(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Success response must carry an "id".');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithBadIdType(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Response "id" must be an int or string, array given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []], EmptyResult::class);
    }

    public function testParseRejectsResultResponseWithNonObjectResult(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'bad'], EmptyResult::class);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertStringContainsString('Success response "result" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseWrapsResultResponseFromArrayFailure(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['_meta' => 'bad']],
                EmptyResult::class,
            );
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertMatchesRegularExpression('/^Invalid .+EmptyResult payload: "result._meta" must be an object/', $e->getMessage());
        }
    }

    public function testEveryParserFailureIsProtocolException(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown/method']);
            self::fail('Expected an AbstractJsonRpcProtocolException.');
        } catch (AbstractJsonRpcProtocolException $e) {
            self::assertSame(ProtocolErrorCode::MethodNotFound, $e::getErrorCode());
            self::assertSame(1, $e->requestId?->id);
        }
    }

    public function testParseRejectsEmptyStringRequestIdAsInvalidRequest(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => '', 'method' => 'unknown/method']);
            self::fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'An empty-string id cannot be wrapped into a RequestId, so the exception carries null.');
            self::assertSame('"id" must be a non-empty string.', $e->getMessage());
        }
    }

    public function testParseDropsRequestIdWhenVersionMismatchIsRaisedAndEnvelopeIdIsEmptyString(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '1.0', 'id' => '', 'method' => 'tests/test-request']);
            self::fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertNull($e->requestId, 'An empty-string envelope id cannot be wrapped into a RequestId, so the exception carries null.');
        }
    }
}
