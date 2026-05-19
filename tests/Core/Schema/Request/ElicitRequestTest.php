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

namespace Nexus\Mcp\Tests\Core\Schema\Request;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\ElicitRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestTest extends TestCase
{
    public function testMethodIsElicitationCreate(): void
    {
        self::assertSame('elicitation/create', ElicitRequest::method());
    }

    public function testToArrayWithFormParams(): void
    {
        $req = new ElicitRequest(
            new RequestId('r-1'),
            new ElicitRequestFormParams(
                'Pick',
                new ElicitRequestedSchema(['x' => new StringSchema()]),
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'r-1',
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Pick',
                    'requestedSchema' => [
                        'type' => 'object',
                        'properties' => ['x' => ['type' => 'string']],
                    ],
                ],
            ],
            $req->toArray(),
        );
    }

    public function testToArrayWithUrlParams(): void
    {
        $req = new ElicitRequest(
            new RequestId(7),
            new ElicitRequestUrlParams('e-1', 'Sign in', 'url', 'https://example.com'),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'elicitation/create',
                'params' => [
                    'elicitationId' => 'e-1',
                    'message' => 'Sign in',
                    'mode' => 'url',
                    'url' => 'https://example.com',
                ],
            ],
            $req->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $req = new ElicitRequest(
            new RequestId('r-1'),
            new ElicitRequestFormParams('Pick', new ElicitRequestedSchema(['x' => new StringSchema()])),
        );

        self::assertSame($req->toArray(), $req->jsonSerialize());
    }

    public function testFromArrayDispatchesFormVariant(): void
    {
        $req = ElicitRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 'r-1',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Pick',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['x' => ['type' => 'string']],
                ],
            ],
        ]);

        self::assertInstanceOf(ElicitRequestFormParams::class, $req->params);
    }

    public function testFromArrayDispatchesUrlVariant(): void
    {
        $req = ElicitRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 'r-1',
            'method' => 'elicitation/create',
            'params' => [
                'elicitationId' => 'e-1',
                'message' => 'Sign in',
                'mode' => 'url',
                'url' => 'https://example.com',
            ],
        ]);

        self::assertInstanceOf(ElicitRequestUrlParams::class, $req->params);
    }

    public function testFromArrayDefaultsToFormVariantWhenModeMissing(): void
    {
        $req = ElicitRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 'r-1',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Pick',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['x' => ['type' => 'string']],
                ],
            ],
        ]);

        self::assertInstanceOf(ElicitRequestFormParams::class, $req->params);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequest(
            new RequestId('r-1'),
            new ElicitRequestUrlParams('e-1', 'Sign in', 'url', 'https://example.com'),
        );

        $rebuilt = ElicitRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [
            ['params' => []],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['id' => 1.5, 'params' => []],
            '"id" must be int or string, float given.',
        ];

        yield 'missing params' => [
            ['id' => 'r'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['id' => 'r', 'params' => 'oops'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['id' => 'r', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];

        yield 'params mode not a string' => [
            ['id' => 'r', 'params' => ['mode' => 1]],
            '"params.mode" must be a string, int given.',
        ];
    }
}
