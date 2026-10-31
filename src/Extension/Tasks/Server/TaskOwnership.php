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

namespace Nexus\Mcp\Extension\Tasks\Server;

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Extension\Tasks\Server\Store\OwnedTaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * Binding of a task to the authenticated caller that created it.
 *
 * @internal
 */
final class TaskOwnership
{
    /**
     * @param non-empty-string             $toolName
     * @param null|array<array-key, mixed> $arguments
     */
    public static function createTask(
        TaskStoreInterface $store,
        ServerContext $context,
        string $toolName,
        ?array $arguments,
        ?int $ttlMs,
        int $pollIntervalMs,
    ): TaskRecord {
        $owner = self::resolveOwner($context);

        if (null === $owner) {
            return $store->createTask($toolName, $arguments, $ttlMs, $pollIntervalMs);
        }

        if (! $store instanceof OwnedTaskStoreInterface) {
            throw new LogicException(\sprintf(
                '%s must implement %s to serve authenticated requests.',
                $store::class,
                OwnedTaskStoreInterface::class,
            ));
        }

        return $store->createOwnedTask($toolName, $arguments, $ttlMs, $pollIntervalMs, $owner);
    }

    /**
     * @param non-empty-string $taskId
     *
     * @throws InvalidParamsException when the task is unknown or belongs to another caller
     */
    public static function findTask(TaskStoreInterface $store, ServerContext $context, string $taskId): TaskRecord
    {
        $record = $store->findTask($taskId);

        if (null === $record || self::resolveOwner($context) !== $record->owner) {
            throw new InvalidParamsException($context->requestId, '"params.taskId" does not name a known task.');
        }

        return $record;
    }

    /**
     * @return null|non-empty-string
     *
     * @throws LogicException when the token names neither a subject nor a client
     */
    private static function resolveOwner(ServerContext $context): ?string
    {
        $token = $context->receiveContext->authInfo;

        if (null === $token) {
            return null;
        }

        if (null === $token->subject && null === $token->clientId) {
            throw new LogicException('A task request needs an access token with a "sub" or "client_id" claim.');
        }

        return json_encode([$token->subject, $token->clientId], \JSON_THROW_ON_ERROR);
    }
}
