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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Sampling\ModelPreferences;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolChoice;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

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
        public ModelPreferences $modelPreferences = new ModelPreferences(),
        ?array $stopSequences = null,
        ?string $systemPrompt = null,
        public ?TaskMetadata $task = null,
        public ?float $temperature = null,
        public ToolChoice $toolChoice = new ToolChoice(),
        ?array $tools = null,
        ?array $metadata = null,
        RequestMetaObject $meta = new RequestMetaObject(),
    ) {
        Assert::that($maxTokens)->isNaturalInt('"params.maxTokens" must be a non-negative integer.');
        Assert::that($messages)->values()->isInstanceOf(SamplingMessage::class);
        Assert::that($systemPrompt)->nullOr()->isNonEmptyString('"params.systemPrompt" must be a non-empty string or null.');

        if (null !== $stopSequences) {
            Assert::that($stopSequences)->values()->isString('each "params.stopSequences" entries must be strings, {type} given.');
        }

        Assert::that($temperature)->nullOr()->isBetween(0.0, 2.0, message: '"params.temperature" must be between 0.0 and 2.0.');

        if (null !== $tools) {
            Assert::that($tools)->values()->isInstanceOf(Tool::class);
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
        Assert::that($data)->hasOffset('maxTokens', 'missing the required "maxTokens" key.');
        $maxTokens = $data['maxTokens'];
        Assert::that($maxTokens)->isInt('"params.maxTokens" must be an int, {type} given.');

        Assert::that($data)->hasOffset('messages', 'missing the required "messages" key.');
        Assert::that($data['messages'])
            ->isList('"params.messages" must be a list, non-list array given.')
            ->values()
            ->isArray('each "params.message" must be an object, {type} given.')
            ->isMap('each "params.message" must be a string-keyed object.')
        ;
        $messages = array_map(SamplingMessage::fromArray(...), $data['messages']);

        $includeContext = null;

        if (\array_key_exists('includeContext', $data)) {
            $includeContext = EnumValueValidator::parse(IncludeContext::class, $data['includeContext'], '"params.includeContext"');
        }

        $modelPreferences = new ModelPreferences();

        if (\array_key_exists('modelPreferences', $data)) {
            Assert::that($data['modelPreferences'])
                ->isArray('"params.modelPreferences" must be an object, {type} given.')
                ->isMap('"params.modelPreferences" must be a string-keyed object.')
            ;
            $modelPreferences = ModelPreferences::fromArray($data['modelPreferences']);
        }

        $stopSequences = null;

        if (\array_key_exists('stopSequences', $data)) {
            Assert::that($data['stopSequences'])
                ->isList('"params.stopSequences" must be a list, non-list array given.')
                ->values()->isString('each "params.stopSequences" must be a string, {type} given.')
            ;
            $stopSequences = $data['stopSequences'];
        }

        $systemPrompt = $data['systemPrompt'] ?? null;
        Assert::that($systemPrompt)->nullOr()->isString('"params.systemPrompt" must be a string or null, {type} given.');

        $task = null;

        if (\array_key_exists('task', $data)) {
            Assert::that($data['task'])
                ->isArray('"params.task" must be an object, {type} given.')
                ->isMap('"params.task" must be a string-keyed object.')
            ;
            $task = TaskMetadata::fromArray($data['task']);
        }

        $temperature = $data['temperature'] ?? null;

        if (null !== $temperature) {
            $temperature = self::parseNumber($temperature, '"params.temperature" must be a number or null, {type} given.');
        }

        $toolChoice = new ToolChoice();

        if (\array_key_exists('toolChoice', $data)) {
            Assert::that($data['toolChoice'])
                ->isArray('"params.toolChoice" must be an object, {type} given.')
                ->isMap('"params.toolChoice" must be a string-keyed object.')
            ;
            $toolChoice = ToolChoice::fromArray($data['toolChoice']);
        }

        $tools = null;

        if (\array_key_exists('tools', $data)) {
            Assert::that($data['tools'])
                ->isList('"params.tools" must be a list, non-list array given.')
                ->values()
                ->isArray('each "params.tool" must be an object, {type} given.')
                ->isMap('each "params.tool" must be a string-keyed object.')
            ;
            $tools = array_map(Tool::fromArray(...), $data['tools']);
        }

        $metadata = null;

        if (\array_key_exists('metadata', $data)) {
            Assert::that($data['metadata'])
                ->isArray('"params.metadata" must be an object, {type} given.')
                ->isMap('"params.metadata" must be a string-keyed object.')
            ;
            $metadata = $data['metadata'];
        }

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

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

        $modelPreferences = $this->modelPreferences->toArray();

        if ([] !== $modelPreferences) {
            $data['modelPreferences'] = $modelPreferences;
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

        $toolChoice = $this->toolChoice->toArray();

        if ([] !== $toolChoice) {
            $data['toolChoice'] = $toolChoice;
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

        if ([] !== $this->modelPreferences->toArray()) {
            $data['modelPreferences'] = $this->modelPreferences->jsonSerialize();
        }

        if (null !== $this->task) {
            $data['task'] = $this->task->jsonSerialize();
        }

        if ([] !== $this->toolChoice->toArray()) {
            $data['toolChoice'] = $this->toolChoice->jsonSerialize();
        }

        if ([] === $this->metadata) {
            $data['metadata'] = new \stdClass();
        }

        return $data;
    }
}
