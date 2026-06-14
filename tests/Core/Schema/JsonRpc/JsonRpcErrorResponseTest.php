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
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $response = new JsonRpcErrorResponse(id: new RequestId(id: 42), error: new InternalError(message: 'boom'));

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
        $response = new JsonRpcErrorResponse(id: null, error: new ParseError(message: 'bad json'));

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
        $response = new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: InternalError::DEFAULT_MESSAGE));

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
            'error' => ['code' => ProtocolErrorCode::InvalidRequest->value, 'message' => 'Invalid request'],
        ]);

        self::assertInstanceOf(InvalidRequestError::class, $response->error);
    }

    public function testFromArrayDispatchesMethodNotFoundError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::MethodNotFound->value, 'message' => 'Method not found'],
        ]);

        self::assertInstanceOf(MethodNotFoundError::class, $response->error);
    }

    public function testFromArrayDispatchesInvalidParamsError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InvalidParams->value, 'message' => 'Invalid params'],
        ]);

        self::assertInstanceOf(InvalidParamsError::class, $response->error);
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

    public function testFromArrayPreservesUnknownCodeAsUnknownProtocolError(): void
    {
        $response = JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32099, 'message' => 'Server error', 'data' => ['trace' => 'abc']],
        ]);

        self::assertInstanceOf(UnknownProtocolError::class, $response->error);
        self::assertSame(-32099, $response->error->code);
        self::assertSame('Server error', $response->error->message);
        self::assertSame(['trace' => 'abc'], $response->error->data);
    }

    public function testFromArrayUnknownCodeRoundTrips(): void
    {
        $envelope = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32099, 'message' => 'Upstream rejected', 'data' => ['detail' => 'x']],
        ];

        $rebuilt = JsonRpcErrorResponse::fromArray($envelope)->toArray();

        self::assertSame($envelope, $rebuilt);
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
            'error' => ['code' => ProtocolErrorCode::ParseError->value, 'message' => 'bad JSON'],
        ]);

        self::assertNull($response->id);
    }

    public function testFromArrayRejectsBadIdType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"id" must be an int, string, or null, array given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => [],
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'oops'],
        ]);
    }

    public function testFromArrayRejectsMissingErrorCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"error" is missing the required "code" key.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['message' => 'oops'],
        ]);
    }

    public function testFromArrayRejectsMissingErrorMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"error" is missing the required "message" key.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value],
        ]);
    }

    public function testFromArrayRejectsNonObjectError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"error" must be an object, string given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => 'bad',
        ]);
    }

    public function testFromArrayRejectsNonIntCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"error.code" must be an integer, string given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => 'not-an-int'],
        ]);
    }

    public function testFromArrayRejectsNonStringMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"error.message" must be a string, int given.');

        JsonRpcErrorResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 42],
        ]);
    }

    #[DataProvider('provideFromArrayAcceptsNonObjectDataCases')]
    public function testFromArrayAcceptsNonObjectData(mixed $data): void
    {
        $envelope = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'oops', 'data' => $data],
        ];

        $response = JsonRpcErrorResponse::fromArray($envelope);

        self::assertSame($data, $response->error->data);
        self::assertSame($envelope, $response->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideFromArrayAcceptsNonObjectDataCases(): iterable
    {
        yield 'string' => ['bad'];

        yield 'integer' => [42];

        yield 'float' => [1.5];

        yield 'boolean' => [true];

        yield 'list' => [[1, 2, 3]];
    }
}
