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
        ?RequestMetaObject $meta = null,
    ) {
        Assert::that($message)->isNonEmptyString('ElicitRequestFormParams message must be a non-empty string.');
        Assert::that($mode)->isIdentical(self::MODE, \sprintf('ElicitRequestFormParams mode must be "%s", {value} given.', self::MODE));

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
        Assert::that($mode)->isString('ElicitRequestFormParams wire "mode" must be a string, {type} given.');

        Assert::that($data)->hasOffset('message', 'ElicitRequestFormParams wire data missing "message".');
        $message = $data['message'];
        Assert::that($message)->isString('ElicitRequestFormParams wire "message" must be a string, {type} given.');

        Assert::that($data)->hasOffset('requestedSchema', 'ElicitRequestFormParams wire data missing "requestedSchema".');
        Assert::that($data['requestedSchema'])
            ->isArray('ElicitRequestFormParams wire "requestedSchema" must be an object, {type} given.')
            ->isMap('ElicitRequestFormParams wire "requestedSchema" must be a string-keyed object.')
        ;
        $requestedSchema = ElicitRequestedSchema::fromArray($data['requestedSchema']);

        $task = TaskMetadata::parseFromWire($data, 'ElicitRequestFormParams');
        $meta = RequestMetaObject::parseFromWire($data, 'Request params');

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
