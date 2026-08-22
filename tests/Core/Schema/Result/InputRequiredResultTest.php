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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InputRequiredResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InputRequiredResultTest extends AbstractMcpTestCase
{
    private const array INPUT_REQUESTS_WIRE = [
        'github_login' => [
            'method' => 'elicitation/create',
            'params' => [
                'mode' => 'form',
                'message' => 'Please provide your GitHub username',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                ],
            ],
        ],
    ];

    public function testToArrayRequestStateOnly(): void
    {
        $result = new InputRequiredResult(requestState: 'tok');

        self::assertNull($result->inputRequests);
        self::assertSame('tok', $result->requestState);
        self::assertSame(
            ['resultType' => 'input_required', 'requestState' => 'tok'],
            $result->toArray(),
        );
    }

    public function testToArrayInputRequestsOnly(): void
    {
        $requests = $this->buildInputRequests();
        $result = new InputRequiredResult(inputRequests: $requests);

        self::assertSame($requests, $result->inputRequests);
        self::assertNull($result->requestState);
        self::assertSame(
            ['resultType' => 'input_required', 'inputRequests' => self::INPUT_REQUESTS_WIRE],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new InputRequiredResult(
            inputRequests: $this->buildInputRequests(),
            requestState: 'tok',
            meta: new GenericResultMetaObject(extras: ['vendor.brand' => 'acme']),
        );

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(
            ['_meta' => ['replaced' => true]] + $result->toArray(),
            $rebuilt->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new InputRequiredResult(
            inputRequests: $this->buildInputRequests(),
            requestState: 'tok',
            meta: new GenericResultMetaObject(extras: ['vendor.brand' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor.brand' => 'acme'],
                'resultType' => 'input_required',
                'inputRequests' => self::INPUT_REQUESTS_WIRE,
                'requestState' => 'tok',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new InputRequiredResult(requestState: 'tok');

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testEmptyInputRequestsNormalisesToNull(): void
    {
        $result = new InputRequiredResult(inputRequests: [], requestState: 'tok');

        self::assertNull($result->inputRequests);
        self::assertSame(
            ['resultType' => 'input_required', 'requestState' => 'tok'],
            $result->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new InputRequiredResult(
            inputRequests: $this->buildInputRequests(),
            requestState: 'tok',
            meta: new GenericResultMetaObject(extras: ['vendor.brand' => 'acme']),
        );

        $rebuilt = InputRequiredResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayDecodesInputRequests(): void
    {
        $result = InputRequiredResult::fromArray([
            'resultType' => 'input_required',
            'inputRequests' => self::INPUT_REQUESTS_WIRE,
        ]);

        self::assertSame(
            self::INPUT_REQUESTS_WIRE,
            array_map(static fn(InputRequest $request): array => $request->toArray(), $result->inputRequests ?? []),
        );
    }

    public function testConstructorRejectsResultWithoutInputRequestsOrRequestState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" must carry at least one of "inputRequests" or "requestState".');

        new InputRequiredResult();
    }

    public function testConstructorRejectsEmptyInputRequestsAsOnlyField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" must carry at least one of "inputRequests" or "requestState".');

        new InputRequiredResult(inputRequests: []);
    }

    public function testConstructorAcceptsAServerAssignedIdThatIsAllDigits(): void
    {
        $result = new InputRequiredResult(inputRequests: ['0' => $this->elicitRequest()]);

        self::assertSame([0], array_keys($result->inputRequests ?? []));
        self::assertStringContainsString('"inputRequests":{"0":', (string) json_encode($result));
    }

    public function testConstructorRejectsNonInputRequestEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must be an InputRequest, string given.');

        // @phpstan-ignore argument.type
        new InputRequiredResult(inputRequests: ['github_login' => 'oops']);
    }

    public function testConstructorRejectsArrayInputRequestEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must be an InputRequest, array given.');

        // @phpstan-ignore argument.type
        new InputRequiredResult(inputRequests: ['github_login' => ['method' => 'elicitation/create']]);
    }

    public function testFromArrayRejectsResultWithoutInputRequestsOrRequestState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" must carry at least one of "inputRequests" or "requestState".');

        InputRequiredResult::fromArray(['resultType' => 'input_required']);
    }

    public function testFromArrayRejectsUnsupportedInputRequestMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must use a supported input-request method, \'sampling/createMessage\' given.');

        InputRequiredResult::fromArray([
            'resultType' => 'input_required',
            'inputRequests' => ['sample' => ['method' => 'sampling/createMessage', 'params' => []]],
        ]);
    }

    public function testFromArraySanitisesNonScalarInputRequestMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must use a supported input-request method, array (\x0a  0 => \'nested\',\x0a) given.');

        InputRequiredResult::fromArray([
            'resultType' => 'input_required',
            'inputRequests' => ['sample' => ['method' => ['nested']]],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        InputRequiredResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'inputRequests not an object' => [
            ['inputRequests' => 'oops'],
            '"result.inputRequests" must be an object, string given.',
        ];

        yield 'inputRequests entry not an object' => [
            ['inputRequests' => ['github_login' => 'oops']],
            'each "result.inputRequests" entry must be an object, string given.',
        ];

        yield 'inputRequests entry list-keyed' => [
            ['inputRequests' => ['github_login' => ['elicitation/create']]],
            'each "result.inputRequests" entry must be a string-keyed object.',
        ];

        yield 'inputRequests entry missing method' => [
            ['inputRequests' => ['github_login' => ['params' => []]]],
            'each "result.inputRequests" entry is missing the required "method" key.',
        ];

        yield 'requestState not a string' => [
            ['requestState' => 42],
            '"result.requestState" must be a string, int given.',
        ];

        yield 'meta not an object' => [
            ['requestState' => 'tok', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield 'meta list-keyed' => [
            ['requestState' => 'tok', '_meta' => ['oops']],
            '"result._meta" must be a string-keyed object.',
        ];
    }

    public function testJsonSerializeEmitsANestedRequestedSchemasEmptyPropertiesAsAnObject(): void
    {
        $result = new InputRequiredResult(inputRequests: ['github_login' => new ElicitRequest(
            params: new ElicitRequestFormParams(message: 'm', requestedSchema: new ElicitRequestedSchema(properties: [])),
        )]);

        self::assertStringContainsString('"requestedSchema":{"type":"object","properties":{}}', (string) json_encode($result));
        $serialized = $result->jsonSerialize();
        self::assertArrayHasKey('inputRequests', $serialized);
        self::assertIsArray($serialized['inputRequests']);
        self::assertArrayHasKey('github_login', $serialized['inputRequests']);
        self::assertIsArray($serialized['inputRequests']['github_login']);
    }

    public function testConstructorRejectsAnEmptyInputRequestKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" key must be an int or non-empty string.');

        // @phpstan-ignore argument.type
        new InputRequiredResult(inputRequests: ['' => $this->elicitRequest()]);
    }

    /**
     * @return array<int|non-empty-string, ElicitRequest>
     */
    private function buildInputRequests(): array
    {
        return ['github_login' => $this->elicitRequest()];
    }

    private function elicitRequest(): ElicitRequest
    {
        return new ElicitRequest(params: new ElicitRequestFormParams(
            message: 'Please provide your GitHub username',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
    }
}
