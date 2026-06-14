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
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\InputResponseRequestParams;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CallToolRequestParams::class)]
#[CoversClass(InputResponseRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CallToolRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create());

        self::assertSame('read-file', $params->name);
        self::assertNull($params->arguments);
    }

    public function testConstructionWithAllFields(): void
    {
        $meta = RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor.brand' => 'acme']);
        $params = new CallToolRequestParams(name: 'read-file', meta: $meta, arguments: ['path' => 'src/']);

        self::assertSame(['path' => 'src/'], $params->arguments);
        self::assertSame($meta, $params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create());

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'read-file'],
            $params->toArray(),
        );
    }

    public function testToArrayWithArguments(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), arguments: ['path' => 'src/']);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'read-file', 'arguments' => ['path' => 'src/']],
            $params->toArray(),
        );
    }

    public function testToArrayWithMetaExtras(): void
    {
        $params = new CallToolRequestParams(
            name: 'read-file',
            meta: RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']),
                'name' => 'read-file',
            ],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyArguments(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), arguments: []);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'read-file'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), arguments: ['path' => 'src/']);

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = CallToolRequestParams::fromArray([
            'name' => 'read-file',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame('read-file', $params->name);
        self::assertNull($params->arguments);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $params = CallToolRequestParams::fromArray([
            'name' => 'read-file',
            'arguments' => ['path' => 'src/'],
            '_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']),
        ]);

        self::assertSame(['path' => 'src/'], $params->arguments);
        self::assertSame(['vendor.brand' => 'acme'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CallToolRequestParams(
            name: 'read-file',
            meta: RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
            arguments: ['path' => 'src/'],
        );

        $rebuilt = CallToolRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\A"params.name" must be 1-128 characters/');

        new CallToolRequestParams(name: 'bad name', meta: RequestMetaObjectFactory::create());
    }

    public function testConstructorRejectsListKeyedArguments(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.arguments" must be a string-keyed map or null.');

        // @phpstan-ignore argument.type
        new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), arguments: ['v1', 'v2']);
    }

    public function testConstructionWithInputResponsesAndRequestState(): void
    {
        $response = new ElicitResult(action: ElicitAction::Accept, content: ['name' => 'octocat']);
        $params = new CallToolRequestParams(
            name: 'read-file',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => $response],
            requestState: 'tok',
        );

        self::assertSame(['github_login' => $response], $params->inputResponses);
        self::assertSame('tok', $params->requestState);
    }

    public function testToArrayWithInputResponsesAndRequestState(): void
    {
        $params = new CallToolRequestParams(
            name: 'read-file',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            requestState: 'tok',
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'name' => 'read-file',
                'inputResponses' => ['github_login' => ['action' => 'accept']],
                'requestState' => 'tok',
            ],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyInputResponses(): void
    {
        $params = new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), inputResponses: []);

        self::assertNull($params->inputResponses);
        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'read-file'],
            $params->toArray(),
        );
    }

    public function testFromArrayParsesInputResponseFields(): void
    {
        $params = CallToolRequestParams::fromArray([
            'name' => 'read-file',
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
        new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), inputResponses: [['action' => 'accept']]);
    }

    public function testConstructorRejectsNonObjectInputResponseEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse, string given.');

        // @phpstan-ignore argument.type
        new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), inputResponses: ['github_login' => 'oops']);
    }

    public function testConstructorRejectsListKeyedInputResponseEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse, array given.');

        // @phpstan-ignore argument.type
        new CallToolRequestParams(name: 'read-file', meta: RequestMetaObjectFactory::create(), inputResponses: ['github_login' => ['accept']]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CallToolRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            '"params" is missing the required "name" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            '"params.name" must be a string, int given.',
        ];

        yield 'arguments not an object' => [
            ['name' => 'read-file', 'arguments' => 'oops'],
            '"params.arguments" must be an object, string given.',
        ];

        yield 'arguments list-keyed' => [
            ['name' => 'read-file', 'arguments' => ['v']],
            '"params.arguments" must be a string-keyed object.',
        ];

        yield 'missing _meta' => [
            ['name' => 'read-file'],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['name' => 'read-file', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield 'inputResponses not an object' => [
            ['name' => 'read-file', 'inputResponses' => 'oops'],
            '"params.inputResponses" must be an object, string given.',
        ];

        yield 'inputResponses list-keyed' => [
            ['name' => 'read-file', 'inputResponses' => [['action' => 'accept']]],
            '"params.inputResponses" must be a string-keyed object.',
        ];

        yield 'inputResponses entry not an object' => [
            ['name' => 'read-file', 'inputResponses' => ['github_login' => 'oops']],
            'each "params.inputResponses" entry must be an object, string given.',
        ];

        yield 'inputResponses entry list-keyed' => [
            ['name' => 'read-file', 'inputResponses' => ['github_login' => ['accept']]],
            'each "params.inputResponses" entry must be a string-keyed object.',
        ];

        yield 'requestState not a string' => [
            ['name' => 'read-file', 'requestState' => 42],
            '"params.requestState" must be a string, int given.',
        ];
    }
}
