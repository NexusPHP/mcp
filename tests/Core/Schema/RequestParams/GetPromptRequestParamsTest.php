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

use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\InputResponseRequestParams;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetPromptRequestParams::class)]
#[CoversClass(InputResponseRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetPromptRequestParamsTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new GetPromptRequestParams(name: 'code-review', meta: RequestMetaObjectFactory::create());

        self::assertSame('code-review', $params->name);
        self::assertNull($params->arguments);
    }

    public function testConstructionWithAllFields(): void
    {
        $meta = RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor.brand' => 'acme']);
        $params = new GetPromptRequestParams(name: 'code-review', meta: $meta, arguments: ['topic' => 'auth']);

        self::assertSame(['topic' => 'auth'], $params->arguments);
        self::assertSame($meta, $params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new GetPromptRequestParams(name: 'code-review', meta: RequestMetaObjectFactory::create());

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testToArrayWithArguments(): void
    {
        $params = new GetPromptRequestParams(name: 'code-review', meta: RequestMetaObjectFactory::create(), arguments: ['topic' => 'auth']);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review', 'arguments' => ['topic' => 'auth']],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyArguments(): void
    {
        $params = new GetPromptRequestParams(name: 'code-review', meta: RequestMetaObjectFactory::create(), arguments: []);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $meta = RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']);
        $params = new GetPromptRequestParams(name: 'code-review', meta: $meta);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new GetPromptRequestParams(name: 'code-review', meta: RequestMetaObjectFactory::create(), arguments: ['topic' => 'auth']);

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'code-review',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame('code-review', $params->name);
        self::assertNull($params->arguments);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'code-review',
            'arguments' => ['topic' => 'auth'],
            '_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']),
        ]);

        self::assertSame(['topic' => 'auth'], $params->arguments);
        self::assertSame(['vendor.brand' => 'acme'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetPromptRequestParams(
            name: 'code-review',
            meta: RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
            arguments: ['topic' => 'auth'],
        );

        $rebuilt = GetPromptRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorAcceptsANameOutsideTheSdksPreferredCharset(): void
    {
        $params = new GetPromptRequestParams(name: 'Project Files', meta: RequestMetaObjectFactory::create());

        self::assertSame('Project Files', $params->name);
    }

    public function testConstructorRejectsAnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params.name" must be a non-empty string, string given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new GetPromptRequestParams(name: '', meta: RequestMetaObjectFactory::create());
    }

    public function testConstructorAcceptsAnArgumentNameThatIsAllDigits(): void
    {
        $params = new GetPromptRequestParams(
            name: 'topic',
            meta: RequestMetaObjectFactory::create(),
            arguments: ['v1', 'v2'],
        );

        self::assertSame([0, 1], array_keys($params->arguments ?? []));
        self::assertStringContainsString('"arguments":{"0":"v1","1":"v2"}', (string) json_encode($params));
    }

    public function testConstructorRejectsNonStringArgumentValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params.arguments" values must all be strings, int given.');

        // @phpstan-ignore argument.type
        new GetPromptRequestParams(name: 'topic', meta: RequestMetaObjectFactory::create(), arguments: ['k' => 1]);
    }

    public function testConstructionWithInputResponsesAndRequestState(): void
    {
        $response = new ElicitResult(action: ElicitAction::Accept);
        $params = new GetPromptRequestParams(
            name: 'code-review',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => $response],
            requestState: 'tok',
        );

        self::assertSame(['github_login' => $response], $params->inputResponses);
        self::assertSame('tok', $params->requestState);
    }

    public function testToArrayWithInputResponsesAndRequestState(): void
    {
        $params = new GetPromptRequestParams(
            name: 'code-review',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            requestState: 'tok',
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'name' => 'code-review',
                'inputResponses' => ['github_login' => ['action' => 'accept']],
                'requestState' => 'tok',
            ],
            $params->toArray(),
        );
    }

    public function testFromArrayParsesInputResponseFields(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'code-review',
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

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetPromptRequestParams::fromArray($payload);
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
            '"params.name" must be a non-empty string, int given.',
        ];

        yield 'arguments not an object' => [
            ['name' => 'topic', 'arguments' => 'oops'],
            '"params.arguments" must be an object, string given.',
        ];

        yield 'argument value not a string' => [
            ['name' => 'topic', 'arguments' => ['k' => 1]],
            '"params.arguments" value must be a string, int given.',
        ];

        yield 'missing _meta' => [
            ['name' => 'topic'],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['name' => 'topic', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'topic', '_meta' => ['v']],
            '"params._meta" must be a string-keyed object.',
        ];

        yield 'inputResponses not an object' => [
            ['name' => 'topic', 'inputResponses' => 'oops'],
            '"params.inputResponses" must be an object, string given.',
        ];

        yield 'inputResponses entry not an object' => [
            ['name' => 'topic', 'inputResponses' => ['github_login' => 'oops']],
            'each "params.inputResponses" entry must be an object, string given.',
        ];

        yield 'inputResponses entry list-keyed' => [
            ['name' => 'topic', 'inputResponses' => ['github_login' => ['accept']]],
            'each "params.inputResponses" entry must be a string-keyed object.',
        ];

        yield 'requestState not a string' => [
            ['name' => 'topic', 'requestState' => 42],
            '"params.requestState" must be a string, int given.',
        ];
    }

    public function testJsonSerializeEmitsANestedInputResponsesDigitContentKeyAsAnObject(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'prompt',
            'inputResponses' => ['ask' => ['action' => 'accept', 'content' => ['0' => 'v']]],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ]);

        self::assertStringContainsString('"content":{"0":"v"}', (string) json_encode($params));

        $serialized = $params->jsonSerialize();
        self::assertArrayHasKey('inputResponses', $serialized);
        self::assertIsArray($serialized['inputResponses']);
        self::assertArrayHasKey('ask', $serialized['inputResponses']);
        self::assertIsArray($serialized['inputResponses']['ask']);
    }

    public function testConstructorAcceptsAServerAssignedIdThatIsAllDigits(): void
    {
        $params = new GetPromptRequestParams(
            name: 'topic',
            meta: RequestMetaObjectFactory::create(),
            inputResponses: ['0' => new ElicitResult(action: ElicitAction::Accept)],
        );

        self::assertSame([0], array_keys($params->inputResponses ?? []));
        self::assertStringContainsString('"inputResponses":{"0":', (string) json_encode($params));
    }
}
