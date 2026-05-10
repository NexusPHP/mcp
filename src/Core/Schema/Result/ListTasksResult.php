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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Task;

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
        ?Meta $meta = null,
    ) {
        Assert::that($this->tasks)->isList('ListTasksResult tasks must be a list, got non-list array.');

        foreach ($this->tasks as $task) {
            Assert::that($task)->isInstanceOf(Task::class);
        }

        parent::__construct($nextCursor, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('tasks', 'ListTasksResult wire data missing "tasks".');
        Assert::that($data['tasks'])->isArray('ListTasksResult wire "tasks" must be an array, {type} given.');

        $tasks = [];

        foreach ($data['tasks'] as $entry) {
            Assert::that($entry)
                ->isArray('ListTasksResult wire task entry must be an object, {type} given.')
                ->isMap('ListTasksResult wire task entry must be a string-keyed object.')
            ;
            $tasks[] = Task::fromArray($entry);
        }

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            Assert::that($data['nextCursor'])->isString('ListTasksResult wire "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($data['nextCursor']);
        }

        $meta = Meta::parseFromWire($data, 'Result');

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
