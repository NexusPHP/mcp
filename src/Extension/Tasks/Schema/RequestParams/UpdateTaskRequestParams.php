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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Result\InputResponse;

/**
 * Parameters for a `tasks/update` request.
 *
 * @extends RequestParams<array{
 *   _meta: template-type<RequestMetaObject, MetaObject, 'T'>,
 *   taskId: non-empty-string,
 *   inputResponses: array<string, array<string, mixed>>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class UpdateTaskRequestParams extends RequestParams
{
    /**
     * @param non-empty-string                                  $taskId
     * @param array<string, array<string, mixed>|InputResponse> $inputResponses
     */
    public function __construct(
        public string $taskId,
        public array $inputResponses,
        RequestMetaObject $meta,
    ) {
        Assert::that($inputResponses)->isMap('"params.inputResponses" must be a string-keyed object.');

        foreach ($inputResponses as $response) {
            if (! $response instanceof InputResponse) {
                Assert::that($response)
                    ->isArray('each "params.inputResponses" entry must be an InputResponse or an object, {type} given.')
                    ->isMap('each "params.inputResponses" entry must be an InputResponse or a string-keyed object.')
                ;
            }
        }

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', '"params" is missing the required "taskId" key.');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isNonEmptyString('"params.taskId" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('inputResponses', '"params" is missing the required "inputResponses" key.');
        Assert::that($data['inputResponses'])
            ->isArray('"params.inputResponses" must be an object, {type} given.')
            ->isMap('"params.inputResponses" must be a string-keyed object.')
            ->values()
            ->isArray('each "params.inputResponses" entry must be an object, {type} given.')
            ->isMap('each "params.inputResponses" entry must be a string-keyed object.')
        ;
        $inputResponses = array_map(
            static function (array $response): array|InputResponse {
                try {
                    return ElicitResult::fromArray($response);
                } catch (\InvalidArgumentException) {
                    return $response;
                }
            },
            $data['inputResponses'],
        );

        Assert::that($data)->hasOffset('_meta', '"params" is missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->not()->isNonEmptyList('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(taskId: $taskId, inputResponses: $inputResponses, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            '_meta' => $this->meta->toArray(),
            'taskId' => $this->taskId,
            'inputResponses' => array_map(
                static fn(array|InputResponse $response): array => $response instanceof InputResponse ? $response->toArray() : $response,
                $this->inputResponses,
            ),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        $data['inputResponses'] = array_map(
            static fn(array|InputResponse $response): array|\stdClass => $response instanceof InputResponse ? $response->jsonSerialize() : $response,
            $this->inputResponses,
        );

        // The slot is required, so an empty map must still encode as `{}`.
        if ([] === $this->inputResponses) {
            $data['inputResponses'] = new \stdClass();
        }

        return $data;
    }
}
