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
use Nexus\Mcp\Core\Schema\Enum\IncludeContext;
use Nexus\Mcp\Core\Schema\ParsesNumber;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Sampling\ModelPreferences;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolChoice;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use Nexus\Mcp\Core\Schema\Tool\Tool;

/**
 * Parameters for a `sampling/createMessage` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#createmessagerequestparams
 */
final readonly class CreateMessageRequestParams extends RequestParams
{
    use ParsesNumber;

    /**
     * @var list<SamplingMessage>
     */
    public array $messages;

    /**
     * @var null|non-empty-string
     */
    public ?string $systemPrompt;

    /**
     * @var null|list<string>
     */
    public ?array $stopSequences;

    /**
     * @var null|array<string, mixed>
     */
    public ?array $metadata;

    /**
     * @var null|list<Tool>
     */
    public ?array $tools;

    /**
     * @param list<SamplingMessage>     $messages
     * @param null|list<string>         $stopSequences
     * @param null|list<Tool>           $tools
     * @param null|array<string, mixed> $metadata
     */
    public function __construct(
        public int $maxTokens,
        array $messages,
        public ?IncludeContext $includeContext = null,
        public ?ModelPreferences $modelPreferences = null,
        ?array $stopSequences = null,
        ?string $systemPrompt = null,
        public ?TaskMetadata $task = null,
        public ?float $temperature = null,
        public ?ToolChoice $toolChoice = null,
        ?array $tools = null,
        ?array $metadata = null,
        ?RequestMeta $meta = null,
    ) {
        if ($maxTokens < 0) {
            throw new \InvalidArgumentException('CreateMessageRequestParams maxTokens must be a non-negative integer.');
        }

        foreach ($messages as $message) {
            Assert::that($message)->isInstanceOf(SamplingMessage::class);
        }

        Assert::that($systemPrompt)->nullOr()->isNonEmptyString('CreateMessageRequestParams systemPrompt must be a non-empty string or null.');

        if (null !== $stopSequences) {
            foreach ($stopSequences as $stop) {
                Assert::that($stop)->isString('CreateMessageRequestParams stopSequences entries must be strings, {type} given.');
            }
        }

        if (null !== $temperature && ($temperature < 0.0 || $temperature > 2.0)) {
            throw new \InvalidArgumentException('CreateMessageRequestParams temperature must be between 0.0 and 2.0.');
        }

        if (null !== $tools) {
            foreach ($tools as $tool) {
                Assert::that($tool)->isInstanceOf(Tool::class);
            }
        }

        $this->messages = $messages;
        $this->systemPrompt = $systemPrompt;
        $this->stopSequences = $stopSequences;
        $this->metadata = $metadata;
        $this->tools = $tools;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('maxTokens', 'CreateMessageRequestParams wire data missing "maxTokens".');
        $maxTokens = $data['maxTokens'];
        Assert::that($maxTokens)->isInt('CreateMessageRequestParams wire "maxTokens" must be an int, {type} given.');

        Assert::that($data)->hasOffset('messages', 'CreateMessageRequestParams wire data missing "messages".');
        Assert::that($data['messages'])->isList('CreateMessageRequestParams wire "messages" must be a list, got non-list array.');

        $messages = [];

        foreach ($data['messages'] as $message) {
            Assert::that($message)
                ->isArray('CreateMessageRequestParams wire message entry must be an object, {type} given.')
                ->isMap('CreateMessageRequestParams wire message entry must be a string-keyed object.')
            ;
            $messages[] = SamplingMessage::fromArray($message);
        }

        $includeContext = null;

        if (\array_key_exists('includeContext', $data)) {
            Assert::that($data['includeContext'])->isString('CreateMessageRequestParams wire "includeContext" must be a string, {type} given.');
            $includeContext = IncludeContext::from($data['includeContext']);
        }

        $modelPreferences = null;

        if (\array_key_exists('modelPreferences', $data)) {
            Assert::that($data['modelPreferences'])
                ->isArray('CreateMessageRequestParams wire "modelPreferences" must be an object, {type} given.')
                ->isMap('CreateMessageRequestParams wire "modelPreferences" must be a string-keyed object.')
            ;
            $modelPreferences = ModelPreferences::fromArray($data['modelPreferences']);
        }

        $stopSequences = null;

        if (\array_key_exists('stopSequences', $data)) {
            Assert::that($data['stopSequences'])->isList('CreateMessageRequestParams wire "stopSequences" must be a list, got non-list array.');

            $stopSequences = [];

            foreach ($data['stopSequences'] as $stop) {
                Assert::that($stop)->isString('CreateMessageRequestParams wire stopSequences entry must be a string, {type} given.');
                $stopSequences[] = $stop;
            }
        }

        $systemPrompt = $data['systemPrompt'] ?? null;
        Assert::that($systemPrompt)->nullOr()->isString('CreateMessageRequestParams wire "systemPrompt" must be a string or null, {type} given.');

        $task = null;

        if (\array_key_exists('task', $data)) {
            Assert::that($data['task'])
                ->isArray('CreateMessageRequestParams wire "task" must be an object, {type} given.')
                ->isMap('CreateMessageRequestParams wire "task" must be a string-keyed object.')
            ;
            $task = TaskMetadata::fromArray($data['task']);
        }

        $temperature = $data['temperature'] ?? null;

        if (null !== $temperature) {
            $temperature = self::parseNumber($temperature, 'CreateMessageRequestParams wire "temperature" must be a number or null, {type} given.');
        }

        $toolChoice = null;

        if (\array_key_exists('toolChoice', $data)) {
            Assert::that($data['toolChoice'])
                ->isArray('CreateMessageRequestParams wire "toolChoice" must be an object, {type} given.')
                ->isMap('CreateMessageRequestParams wire "toolChoice" must be a string-keyed object.')
            ;
            $toolChoice = ToolChoice::fromArray($data['toolChoice']);
        }

        $tools = null;

        if (\array_key_exists('tools', $data)) {
            Assert::that($data['tools'])->isList('CreateMessageRequestParams wire "tools" must be a list, got non-list array.');

            $tools = [];

            foreach ($data['tools'] as $tool) {
                Assert::that($tool)
                    ->isArray('CreateMessageRequestParams wire tool entry must be an object, {type} given.')
                    ->isMap('CreateMessageRequestParams wire tool entry must be a string-keyed object.')
                ;
                $tools[] = Tool::fromArray($tool);
            }
        }

        $metadata = null;

        if (\array_key_exists('metadata', $data)) {
            Assert::that($data['metadata'])
                ->isArray('CreateMessageRequestParams wire "metadata" must be an object, {type} given.')
                ->isMap('CreateMessageRequestParams wire "metadata" must be a string-keyed object.')
            ;
            $metadata = $data['metadata'];
        }

        $meta = RequestMeta::parseFromWire($data, 'Request params');

        return new self(
            $maxTokens,
            $messages,
            $includeContext,
            $modelPreferences,
            $stopSequences,
            $systemPrompt,
            $task,
            $temperature,
            $toolChoice,
            $tools,
            $metadata,
            $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'maxTokens' => $this->maxTokens,
            'messages' => array_map(static fn(SamplingMessage $m): array => $m->toArray(), $this->messages),
        ];

        if (null !== $this->includeContext) {
            $data['includeContext'] = $this->includeContext->value;
        }

        if (null !== $this->modelPreferences) {
            $data['modelPreferences'] = $this->modelPreferences->toArray();
        }

        if (null !== $this->stopSequences) {
            $data['stopSequences'] = $this->stopSequences;
        }

        if (null !== $this->systemPrompt) {
            $data['systemPrompt'] = $this->systemPrompt;
        }

        if (null !== $this->task) {
            $data['task'] = $this->task->toArray();
        }

        if (null !== $this->temperature) {
            $data['temperature'] = $this->temperature;
        }

        if (null !== $this->toolChoice) {
            $data['toolChoice'] = $this->toolChoice->toArray();
        }

        if (null !== $this->tools) {
            $data['tools'] = array_map(static fn(Tool $t): array => $t->toArray(), $this->tools);
        }

        if (null !== $this->metadata) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        $data['messages'] = array_map(
            static fn(SamplingMessage $m): array => $m->jsonSerialize(),
            $this->messages,
        );

        if (null !== $this->modelPreferences) {
            $data['modelPreferences'] = $this->modelPreferences->jsonSerialize();
        }

        if (null !== $this->task) {
            $data['task'] = $this->task->jsonSerialize();
        }

        if (null !== $this->toolChoice) {
            $data['toolChoice'] = $this->toolChoice->jsonSerialize();
        }

        if (null !== $this->metadata && [] === $this->metadata) {
            $data['metadata'] = new \stdClass();
        }

        return $data;
    }
}
