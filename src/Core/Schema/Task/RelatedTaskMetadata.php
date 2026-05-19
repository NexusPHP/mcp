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

namespace Nexus\Mcp\Core\Schema\Task;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Metadata for associating messages with a task.
 * Include this in the `_meta` field under the key `io.modelcontextprotocol/related-task`.
 *
 * @implements Arrayable<array{taskId: non-empty-string}>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#relatedtaskmetadata
 */
final readonly class RelatedTaskMetadata implements Arrayable
{
    /**
     * @var non-empty-string
     */
    public string $taskId;

    public function __construct(string $taskId)
    {
        Assert::that($taskId)->isNonEmptyString('related task metadata "taskId" must be a non-empty string.');

        $this->taskId = $taskId;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', 'related task metadata missing the required "taskId" key.');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('related task metadata "taskId" must be a string, {type} given.');

        return new self($taskId);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['taskId' => $this->taskId];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
