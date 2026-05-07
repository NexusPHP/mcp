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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\PromptReference;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\ResourceTemplateReference;

/**
 * Parameters for a `completion/complete` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#completerequestparams
 */
final readonly class CompleteRequestParams extends RequestParams
{
    /**
     * @var array{name: string, value: string}
     */
    public array $argument;

    /**
     * @var null|array{arguments?: array<string, string>}
     */
    public ?array $context;

    /**
     * @param array{name: string, value: string}            $argument
     * @param null|array{arguments?: array<string, string>} $context
     */
    public function __construct(
        public PromptReference|ResourceTemplateReference $ref,
        array $argument,
        ?array $context = null,
        ?RequestMeta $meta = null,
    ) {
        Assert::that($argument['name'])->isString('CompleteRequestParams argument "name" must be a string, {type} given.');
        Assert::that($argument['value'])->isString('CompleteRequestParams argument "value" must be a string, {type} given.');

        if (null !== $context && \array_key_exists('arguments', $context)) {
            Assert::that($context['arguments'])
                ->isArray('CompleteRequestParams context "arguments" must be an object, {type} given.')
                ->isMap('CompleteRequestParams context "arguments" must be a string-keyed object.')
            ;

            foreach ($context['arguments'] as $value) {
                Assert::that($value)->isString('CompleteRequestParams context argument value must be a string, {type} given.');
            }
        }

        $this->argument = ['name' => $argument['name'], 'value' => $argument['value']];
        $this->context = null === $context ? null : (
            \array_key_exists('arguments', $context) ? ['arguments' => $context['arguments']] : []
        );

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('ref', 'CompleteRequestParams wire data missing "ref".');
        Assert::that($data['ref'])
            ->isArray('CompleteRequestParams wire "ref" must be an object, {type} given.')
            ->isMap('CompleteRequestParams wire "ref" must be a string-keyed object.')
        ;

        Assert::that($data)->hasOffset('argument', 'CompleteRequestParams wire data missing "argument".');
        Assert::that($data['argument'])
            ->isArray('CompleteRequestParams wire "argument" must be an object, {type} given.')
            ->isMap('CompleteRequestParams wire "argument" must be a string-keyed object.')
        ;
        Assert::that($data['argument'])->hasOffset('name', 'CompleteRequestParams wire argument missing "name".');
        Assert::that($data['argument']['name'])->isString('CompleteRequestParams wire argument "name" must be a string, {type} given.');
        Assert::that($data['argument'])->hasOffset('value', 'CompleteRequestParams wire argument missing "value".');
        Assert::that($data['argument']['value'])->isString('CompleteRequestParams wire argument "value" must be a string, {type} given.');

        $context = null;

        if (\array_key_exists('context', $data)) {
            Assert::that($data['context'])
                ->isArray('CompleteRequestParams wire "context" must be an object, {type} given.')
                ->isMap('CompleteRequestParams wire "context" must be a string-keyed object.')
            ;
            $context = [];

            if (\array_key_exists('arguments', $data['context'])) {
                Assert::that($data['context']['arguments'])
                    ->isArray('CompleteRequestParams wire context "arguments" must be an object, {type} given.')
                    ->isMap('CompleteRequestParams wire context "arguments" must be a string-keyed object.')
                ;
                $arguments = [];

                foreach ($data['context']['arguments'] as $key => $value) {
                    Assert::that($value)->isString('CompleteRequestParams wire context argument value must be a string, {type} given.');
                    $arguments[$key] = $value;
                }

                $context['arguments'] = $arguments;
            }
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMeta::fromArray($data['_meta']);
        }

        return new self(
            self::dispatchRef($data['ref']),
            ['name' => $data['argument']['name'], 'value' => $data['argument']['value']],
            $context,
            $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'ref' => $this->ref->toArray(),
            'argument' => $this->argument,
        ];

        if (null !== $this->context) {
            $data['context'] = $this->context;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ExpectationFailedException when `type` is missing or unknown
     */
    private static function dispatchRef(array $data): PromptReference|ResourceTemplateReference
    {
        Assert::that($data)->hasOffset('type', 'CompleteRequestParams ref wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isString('CompleteRequestParams ref wire "type" must be a string, {type} given.');

        return match ($type) {
            PromptReference::TYPE => PromptReference::fromArray($data),
            ResourceTemplateReference::TYPE => ResourceTemplateReference::fromArray($data),
            default => throw new ExpectationFailedException(\sprintf(
                'CompleteRequestParams ref wire "type" must be one of "%s", "%s"; "%s" given.',
                PromptReference::TYPE,
                ResourceTemplateReference::TYPE,
                $type,
            )),
        };
    }
}
