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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ElicitRequestFormParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestFormParamsTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ElicitRequestFormParams(
            message: 'Pick an option',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
        );

        self::assertSame('Pick an option', $params->message);
        self::assertSame('form', $params->mode);
    }

    public function testConstructionWithCustomModeKeepsForm(): void
    {
        $params = new ElicitRequestFormParams(
            message: 'Pick',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
            mode: 'form',
        );

        self::assertSame('form', $params->mode);
    }

    public function testToArrayMinimal(): void
    {
        $params = new ElicitRequestFormParams(
            message: 'Pick',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
        );

        self::assertSame(
            [
                'mode' => 'form',
                'message' => 'Pick',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['x' => ['type' => 'string']],
                ],
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ElicitRequestFormParams(
            message: 'Pick',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequestFormParams(
            message: 'Pick',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
            mode: 'form',
        );

        $rebuilt = ElicitRequestFormParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayDefaultsModeWhenMissing(): void
    {
        $params = ElicitRequestFormParams::fromArray([
            'message' => 'Pick',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['x' => ['type' => 'string']],
            ],
        ]);

        self::assertSame('form', $params->mode);
    }

    public function testConstructorRejectsEmptyMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.message" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestFormParams(message: '', requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]));
    }

    public function testConstructorRejectsWrongMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.mode" must be \'form\', \'url\' given.');

        new ElicitRequestFormParams(
            message: 'Pick',
            requestedSchema: new ElicitRequestedSchema(properties: ['x' => new StringSchema()]),
            // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
            mode: 'url',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ElicitRequestFormParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'mode not a string' => [
            ['mode' => 1, 'message' => 'm', 'requestedSchema' => ['type' => 'object', 'properties' => []]],
            '"params.mode" must be \'form\', 1 given.',
        ];

        yield 'missing message' => [
            ['requestedSchema' => ['type' => 'object', 'properties' => []]],
            '"params" is missing the required "message" key.',
        ];

        yield 'message not a string' => [
            ['message' => 1, 'requestedSchema' => ['type' => 'object', 'properties' => []]],
            '"params.message" must be a non-empty string, int given.',
        ];

        yield 'missing requestedSchema' => [
            ['message' => 'm'],
            '"params" is missing the required "requestedSchema" key.',
        ];

        yield 'requestedSchema not an object' => [
            ['message' => 'm', 'requestedSchema' => 'oops'],
            '"params.requestedSchema" must be an object, string given.',
        ];

        yield 'requestedSchema list-keyed' => [
            ['message' => 'm', 'requestedSchema' => ['x']],
            '"params.requestedSchema" must be a string-keyed object.',
        ];
    }

    public function testJsonSerializeEmitsANestedSchemasEmptyPropertiesAsAnObject(): void
    {
        $params = new ElicitRequestFormParams(
            message: 'm',
            requestedSchema: new ElicitRequestedSchema(properties: []),
        );

        self::assertStringContainsString('"requestedSchema":{"type":"object","properties":{}}', (string) json_encode($params));
    }
}
