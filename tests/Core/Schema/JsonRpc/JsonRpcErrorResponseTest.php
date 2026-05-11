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

namespace Nexus\Mcp\Tests\Core\Schema\JsonRpc;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonRpcErrorResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcErrorResponseTest extends TestCase
{
    public function testToArrayWithCorrelatedId(): void
    {
        $response = new JsonRpcErrorResponse(new RequestId(42), new InternalError('boom'));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 42,
                'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'boom'],
            ],
            $response->toArray(),
        );
    }

    public function testToArrayOmitsIdWhenUnparsable(): void
    {
        $response = new JsonRpcErrorResponse(null, new ParseError('bad json'));

        $envelope = $response->toArray();

        self::assertArrayNotHasKey('id', $envelope);
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'error' => ['code' => ProtocolErrorCode::ParseError->value, 'message' => 'bad json'],
            ],
            $envelope,
        );
    }

    public function testFromArrayAcceptsAbsentIdAndOmitsItOnRoundTrip(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'error' => ['code' => ProtocolErrorCode::ParseError->value, 'message' => 'bad json'],
        ]);

        self::assertNull($response->id);
        self::assertArrayNotHasKey('id', $response->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $response = new JsonRpcErrorResponse(new RequestId(1), new InternalError());

        self::assertSame($response->toArray(), $response->jsonSerialize());
    }

    public function testFromArrayDispatchesParseError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::ParseError->value, 'message' => 'bad json'],
        ]);

        self::assertInstanceOf(ParseError::class, $response->error);
        self::assertSame('bad json', $response->error->message);
    }

    public function testFromArrayDispatchesInvalidRequestError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => ProtocolErrorCode::InvalidRequest->value],
        ]);

        self::assertInstanceOf(InvalidRequestError::class, $response->error);
    }

    public function testFromArrayDispatchesMethodNotFoundError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::MethodNotFound->value],
        ]);

        self::assertInstanceOf(MethodNotFoundError::class, $response->error);
    }

    public function testFromArrayDispatchesInvalidParamsError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InvalidParams->value],
        ]);

        self::assertInstanceOf(InvalidParamsError::class, $response->error);
    }

    public function testFromArrayDispatchesUrlElicitationRequiredErrorPayload(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => ProtocolErrorCode::UrlElicitationRequired->value,
                'message' => 'URL elicitation required',
                'data' => ['elicitations' => []],
            ],
        ]);

        self::assertInstanceOf(UrlElicitationRequiredErrorPayload::class, $response->error);
    }

    public function testFromArrayDispatchesInternalError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'oops'],
        ]);

        self::assertInstanceOf(InternalError::class, $response->error);
        self::assertSame('oops', $response->error->message);
    }

    public function testFromArrayFallsBackToInternalErrorForUnknownCode(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => 42],
        ]);

        self::assertInstanceOf(InternalError::class, $response->error);
    }

    public function testFromArrayPropagatesErrorData(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => ProtocolErrorCode::InternalError->value,
                'message' => 'trace',
                'data' => ['trace' => 'abc'],
            ],
        ]);

        self::assertSame(['trace' => 'abc'], $response->error->data);
    }

    public function testFromArrayWithNullIdYieldsNullCorrelation(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => ProtocolErrorCode::ParseError->value],
        ]);

        self::assertNull($response->id);
    }

    public function testFromArrayRejectsBadIdType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC error response id must be int, string, or null; array given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => [],
            'error' => ['code' => ProtocolErrorCode::InternalError->value],
        ]);
    }

    public function testFromArrayRejectsNonObjectError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC error response "error" must be an object, string given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => 'bad',
        ]);
    }

    public function testFromArrayRejectsNonIntCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC error "code" must be an integer, string given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => 'not-an-int'],
        ]);
    }

    public function testFromArrayRejectsNonStringMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC error "message" must be a string, int given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 42],
        ]);
    }

    public function testFromArrayRejectsNonObjectData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC error "data" must be an object, string given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'data' => 'bad'],
        ]);
    }
}
