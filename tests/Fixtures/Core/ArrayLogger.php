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

namespace Nexus\Mcp\Tests\Fixtures\Core;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that accumulates every call into an in-memory array.
 *
 * @internal
 */
final class ArrayLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public private(set) array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<string>
     */
    public function messagesAtLevel(mixed $level): array
    {
        $messages = [];

        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    public function recordsMatching(mixed $level, string $message): array
    {
        $matches = [];

        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                $matches[] = $record;
            }
        }

        return $matches;
    }
}
