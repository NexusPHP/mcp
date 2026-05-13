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
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\TaskAugmentedRequestParams;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CallToolRequestParams::class)]
#[CoversClass(TaskAugmentedRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CallToolRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new CallToolRequestParams('read-file');

        self::assertSame('read-file', $params->name);
        self::assertNull($params->arguments);
        self::assertNull($params->task);
        self::assertSame([], $params->meta->toArray());
    }

    public function testConstructionWithAllFields(): void
    {
        $task = new TaskMetadata(60000);
        $meta = new RequestMetaObject(new ProgressToken('p-1'), ['vendor.brand' => 'acme']);
        $params = new CallToolRequestParams('read-file', ['path' => 'src/'], $task, $meta);

        self::assertSame(['path' => 'src/'], $params->arguments);
        self::assertSame($task, $params->task);
        self::assertSame($meta, $params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new CallToolRequestParams('read-file');

        self::assertSame(['name' => 'read-file'], $params->toArray());
    }

    public function testToArrayWithArguments(): void
    {
        $params = new CallToolRequestParams('read-file', ['path' => 'src/']);

        self::assertSame(
            ['name' => 'read-file', 'arguments' => ['path' => 'src/']],
            $params->toArray(),
        );
    }

    public function testToArrayWithTaskAndMeta(): void
    {
        $params = new CallToolRequestParams(
            'read-file',
            null,
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor.brand' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor.brand' => 'acme'],
                'task' => ['ttl' => 60000],
                'name' => 'read-file',
            ],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyTask(): void
    {
        $params = new CallToolRequestParams('read-file', null, new TaskMetadata());

        self::assertSame(['name' => 'read-file'], $params->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new CallToolRequestParams('read-file', ['path' => 'src/']);

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = CallToolRequestParams::fromArray(['name' => 'read-file']);

        self::assertSame('read-file', $params->name);
        self::assertNull($params->arguments);
        self::assertNull($params->task);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $params = CallToolRequestParams::fromArray([
            'name' => 'read-file',
            'arguments' => ['path' => 'src/'],
            'task' => ['ttl' => 60000],
            '_meta' => ['vendor.brand' => 'acme'],
        ]);

        self::assertSame(['path' => 'src/'], $params->arguments);
        self::assertNotNull($params->task);
        self::assertSame(60000, $params->task->ttl);
        self::assertSame(['vendor.brand' => 'acme'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CallToolRequestParams(
            'read-file',
            ['path' => 'src/'],
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor.brand' => 'acme']),
        );

        $rebuilt = CallToolRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\ACallToolRequestParams name must be 1-128 characters/');

        new CallToolRequestParams('bad name');
    }

    public function testConstructorRejectsListKeyedArguments(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CallToolRequestParams arguments must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new CallToolRequestParams('read-file', ['v1', 'v2']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        CallToolRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            'CallToolRequestParams data missing "name".',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            'CallToolRequestParams "name" must be a string, int given.',
        ];

        yield 'arguments not an object' => [
            ['name' => 'read-file', 'arguments' => 'oops'],
            'CallToolRequestParams "arguments" must be an object, string given.',
        ];

        yield 'arguments list-keyed' => [
            ['name' => 'read-file', 'arguments' => ['v']],
            'CallToolRequestParams "arguments" must be a string-keyed object.',
        ];

        yield 'task not an object' => [
            ['name' => 'read-file', 'task' => 'oops'],
            'CallToolRequestParams "task" must be an object, string given.',
        ];

        yield 'task list-keyed' => [
            ['name' => 'read-file', 'task' => ['x']],
            'CallToolRequestParams "task" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'read-file', '_meta' => 'oops'],
            'Request params "_meta" must be an object, string given.',
        ];
    }
}
