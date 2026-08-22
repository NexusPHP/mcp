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
use Nexus\Mcp\Core\Http\StandardHeaders;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(StandardHeaders::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class StandardHeadersTest extends AbstractMcpTestCase
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
        self::assertNull((new StandardHeaders())->validate($headers, $body));
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

        yield 'tasks/get with a matching taskId' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tasks/get', 'mcp-name' => 'task-1'],
            self::makeBody('tasks/get', ['taskId' => 'task-1']),
        ];

        yield 'a method that carries no name requirement' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
            self::makeBody('tools/list'),
        ];

        yield 'a stray malformed name on a method that defines none' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list', 'mcp-name' => '=?base64?not-base64!?='],
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
        $error = (new StandardHeaders())->validate($headers, $body);

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

        yield 'a tasks/get taskId header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tasks/get', 'mcp-name' => 'task-1'],
            self::makeBody('tasks/get', ['taskId' => 'task-2']),
            self::NAME_MISMATCH,
        ];

        yield 'a tasks/update taskId header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tasks/update', 'mcp-name' => 'task-1'],
            self::makeBody('tasks/update', ['taskId' => 'task-2']),
            self::NAME_MISMATCH,
        ];

        yield 'a tasks/cancel taskId header disagrees with the body' => [
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tasks/cancel', 'mcp-name' => 'task-1'],
            self::makeBody('tasks/cancel', ['taskId' => 'task-2']),
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
     * @param array<string, mixed>  $body
     * @param array<string, string> $expected
     */
    #[DataProvider('provideBuildMirrorsTheBodyCases')]
    public function testBuildMirrorsTheBody(array $body, array $expected): void
    {
        self::assertSame($expected, (new StandardHeaders())->build($body));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function provideBuildMirrorsTheBodyCases(): iterable
    {
        yield 'a method carrying no name' => [
            self::makeBody('tools/list'),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'],
        ];

        yield 'tools/call mirrors params.name' => [
            self::makeBody('tools/call', ['name' => 'get_weather']),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'get_weather'],
        ];

        yield 'prompts/get mirrors params.name' => [
            self::makeBody('prompts/get', ['name' => 'greeting']),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'prompts/get', 'Mcp-Name' => 'greeting'],
        ];

        yield 'resources/read mirrors params.uri' => [
            self::makeBody('resources/read', ['uri' => 'file:///etc/cfg']),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'resources/read', 'Mcp-Name' => 'file:///etc/cfg'],
        ];

        yield 'tasks/get mirrors params.taskId' => [
            self::makeBody('tasks/get', ['taskId' => 'task-1']),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tasks/get', 'Mcp-Name' => 'task-1'],
        ];

        yield 'a name outside the header-safe set is sentinel-encoded' => [
            self::makeBody('tools/call', ['name' => 'Hello, 世界']),
            [
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => '=?base64?SGVsbG8sIOS4lueVjA==?=',
            ],
        ];

        yield 'a name the body does not carry is omitted' => [
            self::makeBody('tools/call'),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'],
        ];

        yield 'a non-string name is omitted' => [
            self::makeBody('tools/call', ['name' => 42]),
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'],
        ];

        yield 'a body without a protocol version omits the header' => [
            self::makeBody('tools/list', version: null),
            ['Mcp-Method' => 'tools/list'],
        ];

        yield 'a body without a method omits the header' => [
            self::makeBody(null),
            ['MCP-Protocol-Version' => '2026-07-28'],
        ];

        yield 'an empty body mirrors nothing' => [[], []];
    }

    public function testBuildProducesHeadersItsOwnValidatorAccepts(): void
    {
        $body = self::makeBody('resources/read', ['uri' => 'file:///tmp/note with spaces.txt']);

        self::assertNull((new StandardHeaders())->validate((new StandardHeaders())->build($body), $body));
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
