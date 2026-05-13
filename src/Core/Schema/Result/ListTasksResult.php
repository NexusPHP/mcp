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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Task\Task;

/**
 * The response to a tasks/list request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listtasksresult
 */
final readonly class ListTasksResult extends PaginatedResult implements ClientResult, ServerResult
{
    /**
     * @param list<Task> $tasks
     */
    public function __construct(
        public array $tasks,
        ?Cursor $nextCursor = null,
        ?MetaObject $meta = null,
    ) {
        Assert::that($this->tasks)
            ->isList('ListTasksResult tasks must be a list, got non-list array.')
            ->values()->isInstanceOf(Task::class)
        ;

        parent::__construct($nextCursor, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('tasks', 'ListTasksResult data missing "tasks".');
        Assert::that($data['tasks'])
            ->isList('ListTasksResult "tasks" must be a list, {type} given.')
            ->values()
            ->isArray('ListTasksResult task entry must be an object, {type} given.')
            ->isMap('ListTasksResult task entry must be a string-keyed object.')
        ;
        $tasks = array_map(Task::fromArray(...), $data['tasks']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            Assert::that($data['nextCursor'])->isString('ListTasksResult "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($data['nextCursor']);
        }

        $meta = MetaObject::parseFrom($data, 'Result');

        return new self($tasks, $nextCursor, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'tasks' => array_map(static fn(Task $task): array => $task->toArray(), $this->tasks),
        ];
    }
}
