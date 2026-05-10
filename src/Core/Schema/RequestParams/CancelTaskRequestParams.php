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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Parameters for a `tasks/cancel` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#canceltaskrequest
 */
final readonly class CancelTaskRequestParams extends RequestParams
{
    public function __construct(public string $taskId, ?RequestMeta $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', 'CancelTaskRequestParams wire data missing "taskId".');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('CancelTaskRequestParams wire "taskId" must be a string, {type} given.');

        $meta = RequestMeta::parseFromWire($data, 'Request params');

        return new self($taskId, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'taskId' => $this->taskId,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
