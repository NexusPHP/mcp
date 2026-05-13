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

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Task\Task;

/**
 * The response to a tasks/cancel request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#canceltaskresult
 */
final readonly class CancelTaskResult extends Result implements ClientResult, ServerResult
{
    public function __construct(public Task $task, MetaObject $meta = new MetaObject())
    {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $task = Task::fromArray($data);
        $meta = MetaObject::parseFrom($data, 'Result');

        return new self($task, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            ...$this->task->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
