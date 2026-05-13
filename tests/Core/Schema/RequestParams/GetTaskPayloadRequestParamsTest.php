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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetTaskPayloadRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetTaskPayloadRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetTaskPayloadRequestParamsTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $params = new GetTaskPayloadRequestParams('task-abc');

        self::assertSame('task-abc', $params->taskId);
        self::assertNull($params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new GetTaskPayloadRequestParams('task-abc');

        self::assertSame(['taskId' => 'task-abc'], $params->toArray());
    }

    public function testToArrayWithMeta(): void
    {
        $params = new GetTaskPayloadRequestParams('task-abc', new RequestMetaObject(null, ['vendor' => 'x']));

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'taskId' => 'task-abc'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new GetTaskPayloadRequestParams('task-abc');

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetTaskPayloadRequestParams('task-abc', new RequestMetaObject(null, ['vendor' => 'x']));

        $rebuilt = GetTaskPayloadRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('GetTaskPayloadRequestParams data missing "taskId".');

        GetTaskPayloadRequestParams::fromArray([]);
    }

    public function testFromArrayRejectsNonStringTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('GetTaskPayloadRequestParams "taskId" must be a string, int given.');

        GetTaskPayloadRequestParams::fromArray(['taskId' => 42]);
    }
}
