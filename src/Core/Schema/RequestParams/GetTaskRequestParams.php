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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Parameters for a `tasks/get` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskrequest
 */
final readonly class GetTaskRequestParams extends RequestParams
{
    public function __construct(public string $taskId, RequestMetaObject $meta = new RequestMetaObject())
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', 'GetTaskRequestParams data missing "taskId".');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('GetTaskRequestParams "taskId" must be a string, {type} given.');

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

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
