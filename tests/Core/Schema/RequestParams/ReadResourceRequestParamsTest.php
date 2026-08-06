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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReadResourceRequestParams::class)]
#[CoversClass(ResourceRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ReadResourceRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create());

        self::assertSame('file:///x', $params->uri);
    }

    public function testConstructionWithMeta(): void
    {
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        );
        self::assertNotNull($params->meta->progressToken);
        self::assertSame('p-1', $params->meta->progressToken->token);
    }

    public function testToArrayWithMeta(): void
    {
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1')),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1')),
                'uri' => 'file:///x',
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create());

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = ReadResourceRequestParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame('file:///x', $params->uri);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = ReadResourceRequestParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        ]);
        self::assertNotNull($params->meta->progressToken);
        self::assertSame('p-1', $params->meta->progressToken->token);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), ReadResourceRequestParams::fromArray($original->toArray())->toArray());
    }

    public function testConstructionWithInputResponsesAndRequestState(): void
    {
        $response = new ElicitResult(action: ElicitAction::Accept);
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => $response],
            requestState: 'tok',
        );

        self::assertSame(['github_login' => $response], $params->inputResponses);
        self::assertSame('tok', $params->requestState);
    }

    public function testToArrayWithInputResponsesAndRequestState(): void
    {
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            requestState: 'tok',
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'uri' => 'file:///x',
                'inputResponses' => ['github_login' => ['action' => 'accept']],
                'requestState' => 'tok',
            ],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyInputResponses(): void
    {
        $params = new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create(), inputResponses: []);

        self::assertNull($params->inputResponses);
        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'uri' => 'file:///x'],
            $params->toArray(),
        );
    }

    public function testFromArrayParsesInputResponseFields(): void
    {
        $params = ReadResourceRequestParams::fromArray([
            'uri' => 'file:///x',
            'inputResponses' => ['github_login' => ['action' => 'accept']],
            'requestState' => 'tok',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame(
            ['github_login' => ['action' => 'accept']],
            array_map(static fn(InputResponse $response): array => $response->toArray(), $params->inputResponses ?? []),
        );
        self::assertSame('tok', $params->requestState);
    }

    public function testConstructorRejectsListKeyedInputResponses(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.inputResponses" must be a string-keyed object.');

        // @phpstan-ignore argument.type
        new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create(), inputResponses: [['action' => 'accept']]);
    }

    public function testConstructorRejectsNonObjectInputResponseEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse, string given.');

        // @phpstan-ignore argument.type
        new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create(), inputResponses: ['github_login' => 'oops']);
    }

    public function testConstructorRejectsListKeyedInputResponseEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse, array given.');

        // @phpstan-ignore argument.type
        new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create(), inputResponses: ['github_login' => ['accept']]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ReadResourceRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [
            [],
            '"params" is missing the required "uri" key.',
        ];

        yield 'uri not a string' => [
            ['uri' => 1],
            '"params.uri" must be a non-empty string, int given.',
        ];

        yield 'missing _meta' => [
            ['uri' => 'file:///x'],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];

        yield 'inputResponses not an object' => [
            ['uri' => 'file:///x', 'inputResponses' => 'oops'],
            '"params.inputResponses" must be an object, string given.',
        ];

        yield 'inputResponses list-keyed' => [
            ['uri' => 'file:///x', 'inputResponses' => [['action' => 'accept']]],
            '"params.inputResponses" must be a string-keyed object.',
        ];

        yield 'inputResponses entry not an object' => [
            ['uri' => 'file:///x', 'inputResponses' => ['github_login' => 'oops']],
            'each "params.inputResponses" entry must be an object, string given.',
        ];

        yield 'inputResponses entry list-keyed' => [
            ['uri' => 'file:///x', 'inputResponses' => ['github_login' => ['accept']]],
            'each "params.inputResponses" entry must be a string-keyed object.',
        ];

        yield 'requestState not a string' => [
            ['uri' => 'file:///x', 'requestState' => 42],
            '"params.requestState" must be a string, int given.',
        ];
    }

    #[DataProvider('provideConstructorRejectsAMalformedUriCases')]
    public function testConstructorRejectsAMalformedUri(string $uri, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ReadResourceRequestParams(uri: $uri, meta: RequestMetaObjectFactory::create());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideConstructorRejectsAMalformedUriCases(): iterable
    {
        yield 'empty' => ['', '"params.uri" must be a non-empty string.'];

        yield 'embedded space' => [
            'file:///tmp/a b.txt',
            '"params.uri" must contain only ASCII printable characters (no whitespace or control characters), \'file:///tmp/a b.txt\' given.',
        ];

        yield 'relative reference' => [
            'tmp/a.txt',
            '"params.uri" must be a valid RFC 3986 absolute URI, \'tmp/a.txt\' given.',
        ];
    }
}
