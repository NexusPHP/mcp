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
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(JsonRpcMessageParser::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcMessageParserTest extends AbstractMcpTestCase
{
    public function testRequestWithNullParamsSerializesWithoutParamsKey(): void
    {
        $request = new TestRequest(new RequestId(id: 42));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tests/test-request'],
            $request->toArray(),
        );
    }

    public function testDiscoverRequestRoundTripUsesDefaultRegistration(): void
    {
        $original = new DiscoverRequest(id: new RequestId(id: 42), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()));

        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse($original->toArray());

        self::assertInstanceOf(DiscoverRequest::class, $parsed);

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
            id: new RequestId(id: 'req-1'),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'tok-1'), ['vendor' => 'x'])),
        );

        $envelope = $original->toArray();

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'method' => 'server/discover',
                'params' => ['_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'tok-1'), ['vendor' => 'x'])],
            ],
            $envelope,
        );

        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse($envelope);

        self::assertInstanceOf(DiscoverRequest::class, $parsed);

        $progressToken = $parsed->params->meta->progressToken;

        if (null === $progressToken) {
            self::fail('Expected progressToken to be set.');
        }

        self::assertSame('req-1', $parsed->id->id);
        self::assertSame('tok-1', $progressToken->token);
        self::assertSame(['vendor' => 'x'], $parsed->params->meta->extras);
    }

    public function testParseDecodesSuccessResponseViaTypedEnvelope(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => ['content' => []]],
            CallToolResultResponse::class,
        );

        self::assertInstanceOf(CallToolResultResponse::class, $parsed);

        self::assertInstanceOf(CallToolResult::class, $parsed->result);
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

        self::assertInstanceOf(TestNotification::class, $parsed);

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
            self::assertSame('Invalid success response: "id" must be an int or non-empty string, string given.', $e->getMessage());
        }
    }

    public function testParseRejectsEmptyStringIdWithTypedResultAsInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid success response: "id" must be an int or non-empty string, string given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => '', 'result' => []], CallToolResultResponse::class);
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
            self::assertSame('Invalid "tests/test-request" request: "id" must be an int or non-empty string, array given.', $e->getMessage());
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

    public function testParseBoundsAPeerValueReflectedThroughANestedCause(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse($this->buildCompletionEnvelopeWithRefType(str_repeat('A', 200_000)));
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            $prefix = 'Invalid "completion/complete" request: ';

            self::assertStringStartsWith($prefix, $e->getMessage());
            self::assertSame(256, \strlen($e->getMessage()) - \strlen($prefix));
            self::assertStringEndsWith('...', $e->getMessage());
        }
    }

    public function testParseEscapesControlBytesReflectedThroughANestedCause(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse($this->buildCompletionEnvelopeWithRefType("seg\x1b[2K\x1b[1GFORGED\x07"));
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertStringContainsString('\\x1b[2K\\x1b[1GFORGED\\x07', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $e->getMessage());
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
        $this->expectExceptionMessageIs('Invalid JSON-RPC version: expected "2.0", got null for method \'tests/test-request\'.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['id' => 1, 'method' => 'tests/test-request']);
    }

    public function testParseRejectsWrongVersionWithoutAMethodTailWhenTheEnvelopeNamesNone(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid JSON-RPC version: expected "2.0", got \'1.0\'.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '1.0', 'id' => 1]);
    }

    public function testParseEscapesControlCharactersInWrongVersionMessage(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => "1.0\ninjected", 'id' => 1, 'method' => 'tests/test-request']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(
                'Invalid JSON-RPC version: expected "2.0", got \'1.0\x0ainjected\' for method \'tests/test-request\'.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    public function testParseEscapesControlCharactersInTheWrongVersionMethodTail(): void
    {
        try {
            (new JsonRpcMessageParser())->parse(['jsonrpc' => '1.0', 'id' => 1, 'method' => "evil\x1bmethod"]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(
                'Invalid JSON-RPC version: expected "2.0", got \'1.0\' for method \'evil\x1bmethod\'.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString("\x1b", $e->getMessage());
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

    public function testParseRejectsAMethodCarryingAResultAndEchoesTheId(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'result' => null]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame('JSON-RPC envelope must not carry a "method" together with a "result" or an "error".', $e->getMessage());
            self::assertNotNull($e->requestId);
            self::assertSame(1, $e->requestId->id);
        }
    }

    public function testParseRejectsAMethodCarryingAnErrorAndEchoesTheId(): void
    {
        $parser = new JsonRpcMessageParser();

        try {
            $parser->parse(['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'tools/list', 'error' => ['code' => -1, 'message' => 'x']]);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame('JSON-RPC envelope must not carry a "method" together with a "result" or an "error".', $e->getMessage());
            self::assertNotNull($e->requestId);
            self::assertSame('abc', $e->requestId->id);
        }
    }

    public function testParseRejectsAMethodCarryingAResponsePayloadWithoutAnId(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('JSON-RPC envelope must not carry a "method" together with a "result" or an "error".');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'method' => 'tools/list', 'result' => null]);
    }

    public function testParseRejectsMissingIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid success response: missing the required "id" key.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'result' => ['payload' => 'x']]);
    }

    public function testParseRejectsNonScalarIdOnResultEnvelopeEvenWhenResultClassOmitted(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid success response: "id" must be an int or non-empty string, array given.');

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
        $this->expectExceptionMessageIs('Invalid success response: missing the required "id" key.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'result' => []], CallToolResultResponse::class);
    }

    public function testParseRejectsResultResponseWithBadIdType(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessageIs('Invalid success response: "id" must be an int or non-empty string, array given.');

        $parser = new JsonRpcMessageParser();
        $parser->parse(['jsonrpc' => '2.0', 'id' => [], 'result' => []], CallToolResultResponse::class);
    }

    public function testParseRejectsResultResponseWithNonObjectResult(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'bad'], CallToolResultResponse::class);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame('Invalid success response: "result" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseWrapsResultResponseFromArrayFailure(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [], '_meta' => 'bad']],
                CallToolResultResponse::class,
            );
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame('Invalid success response: "result._meta" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseDiscriminatesInputRequiredResult(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['resultType' => 'input_required', 'requestState' => 'tok']],
            CallToolResultResponse::class,
        );

        self::assertInstanceOf(JsonRpcResultResponse::class, $parsed);

        $result = $parsed->result;

        self::assertInstanceOf(InputRequiredResult::class, $result);

        self::assertSame('tok', $result->requestState);
        self::assertSame(7, $parsed->id->id);
    }

    public function testParseDecodesUnknownResultTypeAsExpectedClass(): void
    {
        $parser = new JsonRpcMessageParser();
        $parsed = $parser->parse(
            ['jsonrpc' => '2.0', 'id' => 8, 'result' => ['resultType' => 'task', 'content' => []]],
            CallToolResultResponse::class,
        );

        self::assertInstanceOf(JsonRpcResultResponse::class, $parsed);

        self::assertInstanceOf(CallToolResult::class, $parsed->result);
    }

    public function testParseWrapsInvalidInputRequiredResultPayloadAsInvalidRequest(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'input_required', 'inputRequests' => 'bad']],
                CallToolResultResponse::class,
            );
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame('Invalid success response: "result.inputRequests" must be an object, string given.', $e->getMessage());
        }
    }

    public function testParseRejectsInputRequiredForUnsupportedEnvelope(): void
    {
        try {
            $parser = new JsonRpcMessageParser();
            $parser->parse(
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'input_required', 'requestState' => 'tok']],
                ListToolsResultResponse::class,
            );
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(1, $e->requestId?->id);
            self::assertSame('Invalid success response: "result" returned "input_required" for a method that does not support it.', $e->getMessage());
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
            self::assertSame('Invalid "unknown/method" request: "id" must be an int or non-empty string, string given.', $e->getMessage());
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

    /**
     * @return array<string, mixed>
     */
    private function buildCompletionEnvelopeWithRefType(string $type): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'completion/complete',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
                'ref' => ['type' => $type, 'name' => 'x'],
                'argument' => ['name' => 'a', 'value' => 'v'],
            ],
        ];
    }
}
