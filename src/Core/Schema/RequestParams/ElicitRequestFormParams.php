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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;

/**
 * The parameters for a request to elicit non-sensitive information from the user via a form in the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#elicitrequestformparams
 */
final readonly class ElicitRequestFormParams extends TaskAugmentedRequestParams implements ElicitRequestParams
{
    public const string MODE = 'form';

    /**
     * @var non-empty-string
     */
    public string $message;

    /**
     * @var 'form'
     */
    public string $mode;

    public function __construct(
        string $message,
        public ElicitRequestedSchema $requestedSchema,
        string $mode = self::MODE,
        ?TaskMetadata $task = null,
        RequestMetaObject $meta = new RequestMetaObject(),
    ) {
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string.');
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');

        $this->message = $message;
        $this->mode = $mode;

        parent::__construct($task, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $mode = $data['mode'] ?? self::MODE;
        Assert::that($mode)->isString('"params.mode" must be a string, {type} given.');

        Assert::that($data)->hasOffset('message', 'missing the required "message" key.');
        $message = $data['message'];
        Assert::that($message)->isString('"params.message" must be a string, {type} given.');

        Assert::that($data)->hasOffset('requestedSchema', 'missing the required "requestedSchema" key.');
        Assert::that($data['requestedSchema'])
            ->isArray('"params.requestedSchema" must be an object, {type} given.')
            ->isMap('"params.requestedSchema" must be a string-keyed object.')
        ;
        $requestedSchema = ElicitRequestedSchema::fromArray($data['requestedSchema']);

        $task = null;

        if (\array_key_exists('task', $data)) {
            Assert::that($data['task'])
                ->isArray('"params.task" must be an object, {type} given.')
                ->isMap('"params.task" must be a string-keyed object.')
            ;
            $task = TaskMetadata::fromArray($data['task']);
        }

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

        return new self($message, $requestedSchema, $mode, $task, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'mode' => $this->mode,
            'message' => $this->message,
            'requestedSchema' => $this->requestedSchema->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
