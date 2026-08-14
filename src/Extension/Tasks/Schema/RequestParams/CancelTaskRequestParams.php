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

namespace Nexus\Mcp\Extension\Tasks\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Parameters for a `tasks/cancel` request.
 *
 * @extends RequestParams<array{
 *   _meta: template-type<RequestMetaObject, MetaObject, 'T'>,
 *   taskId: non-empty-string,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class CancelTaskRequestParams extends RequestParams
{
    /**
     * @param non-empty-string $taskId
     */
    public function __construct(
        public string $taskId,
        RequestMetaObject $meta,
    ) {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', '"params" is missing the required "taskId" key.');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isNonEmptyString('"params.taskId" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('_meta', '"params" is missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->not()->isNonEmptyList('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(taskId: $taskId, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            '_meta' => $this->meta->toArray(),
            'taskId' => $this->taskId,
        ];
    }
}
