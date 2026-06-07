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
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\InitializeRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializeRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InitializeRequestTest extends TestCase
{
    public function testMethodIsInitialize(): void
    {
        self::assertSame('initialize', InitializeRequest::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new InitializeRequest(
            new RequestId(1),
            new InitializeRequestParams(
                new ProtocolVersion('2025-11-25'),
                new ClientCapabilities(roots: ['listChanged' => true]),
                new Implementation('client', '1.0.0'),
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => ['roots' => ['listChanged' => true]],
                    'clientInfo' => ['name' => 'client', 'version' => '1.0.0'],
                ],
            ],
            $request->toArray(),
        );
    }

    public function testJsonEncodeSubstitutesStdClassForNestedEmptyCapabilitySlots(): void
    {
        $request = new InitializeRequest(
            new RequestId('req-1'),
            new InitializeRequestParams(
                new ProtocolVersion('2025-11-25'),
                new ClientCapabilities(roots: [], sampling: ['context' => []]),
                new Implementation('client', '1.0.0'),
            ),
        );

        $json = json_encode($request);

        self::assertIsString($json);
        self::assertStringContainsString('"roots":{}', $json);
        self::assertStringContainsString('"context":{}', $json);
        self::assertStringNotContainsString('"roots":[]', $json);
        self::assertStringNotContainsString('"context":[]', $json);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new InitializeRequest(
            new RequestId('req-7'),
            new InitializeRequestParams(
                new ProtocolVersion('2025-11-25'),
                new ClientCapabilities(roots: ['listChanged' => true]),
                new Implementation('client', '1.0.0'),
            ),
        );

        $rebuilt = InitializeRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        InitializeRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validParams = [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'c', 'version' => '1'],
        ];

        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => $validParams],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'initialize', 'params' => $validParams],
            '"id" must be an int or string, array given.',
        ];

        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
