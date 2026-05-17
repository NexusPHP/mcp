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

namespace Nexus\Mcp\Core\Schema\Tool;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Execution-related properties for a tool.
 *
 * @implements Arrayable<array{taskSupport?: 'forbidden'|'optional'|'required'}>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#toolexecution
 */
final readonly class ToolExecution implements Arrayable
{
    public function __construct(public ?TaskSupport $taskSupport = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $taskSupport = null;

        if (\array_key_exists('taskSupport', $data)) {
            $taskSupport = EnumValueValidator::parse(TaskSupport::class, $data['taskSupport'], 'ToolExecution "taskSupport"');
        }

        return new self($taskSupport);
    }

    #[\Override]
    public function toArray(): array
    {
        if (null === $this->taskSupport) {
            return [];
        }

        return ['taskSupport' => $this->taskSupport->value];
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
