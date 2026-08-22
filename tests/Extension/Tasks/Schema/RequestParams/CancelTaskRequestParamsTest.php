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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\RequestParams;

use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\CancelTaskRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CancelTaskRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class CancelTaskRequestParamsTest extends AbstractMcpTestCase
{
    public function testConstruction(): void
    {
        $params = new CancelTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create());

        self::assertSame('task-1', $params->taskId);
    }

    public function testToArray(): void
    {
        $params = new CancelTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create());

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'taskId' => 'task-1',
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new CancelTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create());

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new CancelTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create());

        self::assertSame($original->toArray(), CancelTaskRequestParams::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CancelTaskRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing taskId' => [
            [],
            '"params" is missing the required "taskId" key.',
        ];

        yield 'taskId not a string' => [
            ['taskId' => 1],
            '"params.taskId" must be a non-empty string, int given.',
        ];

        yield 'taskId empty' => [
            ['taskId' => ''],
            '"params.taskId" must be a non-empty string, string given.',
        ];

        yield 'missing _meta' => [
            ['taskId' => 'task-1'],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['taskId' => 'task-1', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['taskId' => 'task-1', '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
