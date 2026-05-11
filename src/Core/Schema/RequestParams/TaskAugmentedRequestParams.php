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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;

/**
 * Common params for any task-augmented request.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
abstract readonly class TaskAugmentedRequestParams extends RequestParams
{
    public function __construct(public ?TaskMetadata $task = null, ?RequestMetaObject $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = parent::toArray();

        if (null !== $this->task) {
            $task = $this->task->toArray();

            if ([] !== $task) {
                $data['task'] = $task;
            }
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
