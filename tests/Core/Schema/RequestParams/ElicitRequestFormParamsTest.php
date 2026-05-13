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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\RequestParams\TaskAugmentedRequestParams;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitRequestFormParams::class)]
#[CoversClass(TaskAugmentedRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestFormParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ElicitRequestFormParams(
            'Pick an option',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
        );

        self::assertSame('Pick an option', $params->message);
        self::assertSame('form', $params->mode);
        self::assertNull($params->task);
        self::assertNull($params->meta);
    }

    public function testConstructionWithCustomModeKeepsForm(): void
    {
        $params = new ElicitRequestFormParams(
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
            'form',
        );

        self::assertSame('form', $params->mode);
    }

    public function testToArrayMinimal(): void
    {
        $params = new ElicitRequestFormParams(
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
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

    public function testToArrayWithAllFields(): void
    {
        $params = new ElicitRequestFormParams(
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
            'form',
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'task' => ['ttl' => 60000],
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
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequestFormParams(
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
            'form',
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor' => 'x']),
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
        $this->expectExceptionMessage('ElicitRequestFormParams message must be a non-empty string.');

        new ElicitRequestFormParams('', new ElicitRequestedSchema(['x' => new StringSchema()]));
    }

    public function testConstructorRejectsWrongMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestFormParams mode must be "form", \'url\' given.');

        new ElicitRequestFormParams(
            'Pick',
            new ElicitRequestedSchema(['x' => new StringSchema()]),
            'url',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitRequestFormParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'mode not a string' => [
            ['mode' => 1, 'message' => 'm', 'requestedSchema' => ['type' => 'object', 'properties' => []]],
            'ElicitRequestFormParams "mode" must be a string, int given.',
        ];

        yield 'missing message' => [
            ['requestedSchema' => ['type' => 'object', 'properties' => []]],
            'ElicitRequestFormParams data missing "message".',
        ];

        yield 'message not a string' => [
            ['message' => 1, 'requestedSchema' => ['type' => 'object', 'properties' => []]],
            'ElicitRequestFormParams "message" must be a string, int given.',
        ];

        yield 'missing requestedSchema' => [
            ['message' => 'm'],
            'ElicitRequestFormParams data missing "requestedSchema".',
        ];

        yield 'requestedSchema not an object' => [
            ['message' => 'm', 'requestedSchema' => 'oops'],
            'ElicitRequestFormParams "requestedSchema" must be an object, string given.',
        ];

        yield 'requestedSchema list-keyed' => [
            ['message' => 'm', 'requestedSchema' => ['x']],
            'ElicitRequestFormParams "requestedSchema" must be a string-keyed object.',
        ];
    }
}
