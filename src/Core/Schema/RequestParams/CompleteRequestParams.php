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
use Nexus\Mcp\Core\JsonRpc\MessageDiscriminator;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;

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
        RequestMetaObject $meta = new RequestMetaObject(),
    ) {
        Assert::that($argument['name'])->isString('"params.argument.name" must be a string, {type} given.');
        Assert::that($argument['value'])->isString('"params.argument.value" must be a string, {type} given.');

        if (null !== $context && \array_key_exists('arguments', $context)) {
            Assert::that($context['arguments'])
                ->isArray('"params.context.arguments" must be an object, {type} given.')
                ->isMap('"params.context.arguments" must be a string-keyed object.')
                ->values()->isString('each "params.context.arguments" must be a string, {type} given.')
            ;
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
        Assert::that($data)->hasOffset('ref', 'missing the required "ref" key.');
        Assert::that($data['ref'])
            ->isArray('"params.ref" must be an object, {type} given.')
            ->isMap('"params.ref" must be a string-keyed object.')
        ;

        Assert::that($data)->hasOffset('argument', 'missing the required "argument" key.');
        Assert::that($data['argument'])
            ->isArray('"params.argument" must be an object, {type} given.')
            ->isMap('"params.argument" must be a string-keyed object.')
        ;
        Assert::that($data['argument'])->hasOffset('name', 'missing the required "argument.name" key.');
        Assert::that($data['argument']['name'])->isString('"params.argument.name" must be a string, {type} given.');
        Assert::that($data['argument'])->hasOffset('value', 'missing the required "argument.value" key.');
        Assert::that($data['argument']['value'])->isString('"params.argument.value" must be a string, {type} given.');

        $context = null;

        if (\array_key_exists('context', $data)) {
            Assert::that($data['context'])
                ->isArray('"params.context" must be an object, {type} given.')
                ->isMap('"params.context" must be a string-keyed object.')
            ;
            $context = [];

            if (\array_key_exists('arguments', $data['context'])) {
                Assert::that($data['context']['arguments'])
                    ->isArray('"params.context.arguments" must be an object, {type} given.')
                    ->isMap('"params.context.arguments" must be a string-keyed object.')
                    ->values()->isString('each "params.context.arguments" must be a string, {type} given.')
                ;
                $context['arguments'] = $data['context']['arguments'];
            }
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

        if ([] !== ($this->context['arguments'] ?? [])) {
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
     * @throws ExpectationFailedException
     */
    private static function dispatchRef(array $data): PromptReference|ResourceTemplateReference
    {
        $type = MessageDiscriminator::readType($data, '"params.ref"');

        return match ($type) {
            PromptReference::TYPE => PromptReference::fromArray($data),
            ResourceTemplateReference::TYPE => ResourceTemplateReference::fromArray($data),
            default => throw MessageDiscriminator::unknownType(
                '"params.ref"',
                [PromptReference::TYPE, ResourceTemplateReference::TYPE],
                $type,
            ),
        };
    }
}
