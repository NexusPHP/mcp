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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\HeaderValueCodec;
use Nexus\Mcp\Core\Http\StandardHeaderValidator;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StandardHeaderValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class StandardHeaderValidatorTest extends TestCase
{
    private const string VERSION_ABSENT = 'The MCP-Protocol-Version header is required but absent.';
    private const string VERSION_MISMATCH = 'The MCP-Protocol-Version header does not match the request body protocol version.';
    private const string METHOD_ABSENT = 'The Mcp-Method header is required but absent.';
    private const string METHOD_MISMATCH = 'The Mcp-Method header does not match the request body method.';
    private const string NAME_ABSENT = 'The Mcp-Name header is required but absent.';
    private const string NAME_MISMATCH = 'The Mcp-Name header does not match the request body.';
    private const string NAME_INVALID = 'The Mcp-Name header is not a valid encoded value.';

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    #[DataProvider('provideValidateAcceptsCases')]
    public function testValidateAccepts(array $headers, array $body): void
    {
        self::assertNull(StandardHeaderValidator::validate($headers, $body));
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function provideValidateAcceptsCases(): iterable
    {
        yield 'tools/call with a matching name' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'get_weather'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
        ];

        yield 'header names are matched case-insensitively' => [
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'get_weather'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
        ];

        yield 'prompts/get with a matching name' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/get', 'mcp-name' => 'greeting'],
            self::makeBody('prompts/get', ['name' => 'greeting']),
        ];

        yield 'resources/read with a matching uri' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'file:///a'],
            self::makeBody('resources/read', ['uri' => 'file:///a']),
        ];

        yield 'a method that carries no name requirement' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
            self::makeBody('tools/list'),
        ];

        yield 'an encoded name matching a non-ASCII body value' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => HeaderValueCodec::encode('wörld')],
            self::makeBody('tools/call', ['name' => 'wörld']),
        ];

        yield 'a body without a protocol version skips the version cross-check' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
            self::makeBody('tools/list', version: null),
        ];

        yield 'a body without a method skips the method cross-check' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call'],
            self::makeBody(null),
        ];

        yield 'a missing source value with no name header' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call'],
            self::makeBody('tools/call'),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    #[DataProvider('provideValidateRejectsCases')]
    public function testValidateRejects(array $headers, array $body, string $expectedMessage): void
    {
        $error = StandardHeaderValidator::validate($headers, $body);

        self::assertInstanceOf(HeaderMismatchError::class, $error);
        self::assertSame($expectedMessage, $error->message);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>, string}>
     */
    public static function provideValidateRejectsCases(): iterable
    {
        yield 'the protocol version header is absent' => [
            ['mcp-method' => 'tools/call', 'mcp-name' => 'get_weather'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
            self::VERSION_ABSENT,
        ];

        yield 'the protocol version header disagrees with the body' => [
            ['mcp-protocol-version' => '2025-11-25', 'mcp-method' => 'tools/list'],
            self::makeBody('tools/list'),
            self::VERSION_MISMATCH,
        ];

        yield 'the method header is absent' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-name' => 'get_weather'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
            self::METHOD_ABSENT,
        ];

        yield 'the method header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list', 'mcp-name' => 'get_weather'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
            self::METHOD_MISMATCH,
        ];

        yield 'the name header is absent while the body carries a name' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call'],
            self::makeBody('tools/call', ['name' => 'get_weather']),
            self::NAME_ABSENT,
        ];

        yield 'the name header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'foo'],
            self::makeBody('tools/call', ['name' => 'bar']),
            self::NAME_MISMATCH,
        ];

        yield 'a prompts/get name header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/get', 'mcp-name' => 'foo'],
            self::makeBody('prompts/get', ['name' => 'bar']),
            self::NAME_MISMATCH,
        ];

        yield 'the name header carries an invalid encoded value' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => '=?base64?bad*?='],
            self::makeBody('tools/call', ['name' => 'get_weather']),
            self::NAME_INVALID,
        ];

        yield 'the resources/read uri header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'file:///a'],
            self::makeBody('resources/read', ['uri' => 'file:///b']),
            self::NAME_MISMATCH,
        ];

        yield 'the version check precedes the method check' => [
            ['mcp-method' => 'tools/list'],
            self::makeBody('tools/call'),
            self::VERSION_ABSENT,
        ];

        yield 'the method check precedes the name check' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list', 'mcp-name' => 'foo'],
            self::makeBody('tools/call', ['name' => 'bar']),
            self::METHOD_MISMATCH,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function makeBody(?string $method, array $params = [], ?string $version = '2026-07-28'): array
    {
        $meta = null === $version ? [] : ['io.modelcontextprotocol/protocolVersion' => $version];
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'params' => $params + ['_meta' => $meta]];

        if (null !== $method) {
            $body['method'] = $method;
        }

        return $body;
    }
}
