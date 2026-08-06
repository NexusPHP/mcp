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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Parameters for a `prompts/get` request.
 *
 * @extends InputResponseRequestParams<array{
 *   _meta: template-type<RequestMetaObject, MetaObject, 'T'>,
 *   name: non-empty-string,
 *   arguments?: array<string, string>,
 *   inputResponses?: array<string, array<string, mixed>>,
 *   requestState?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#getpromptrequestparams
 */
final readonly class GetPromptRequestParams extends InputResponseRequestParams
{
    /**
     * @param non-empty-string                  $name
     * @param null|array<string, string>        $arguments
     * @param null|array<string, InputResponse> $inputResponses
     */
    public function __construct(
        public string $name,
        RequestMetaObject $meta,
        public ?array $arguments = null,
        ?array $inputResponses = null,
        ?string $requestState = null,
    ) {
        IdentifierNameValidator::validate($name, '"params.name"');

        if (null !== $arguments) {
            Assert::that($arguments)
                ->isMap('"params.arguments" must be a string-keyed map.')
                ->values()->isString('"params.arguments" values must all be strings, {type} given.')
            ;
        }

        parent::__construct(inputResponses: $inputResponses, requestState: $requestState, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', '"params" is missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isNonEmptyString('"params.name" must be a non-empty string, {type} given.');

        $arguments = null;

        if (\array_key_exists('arguments', $data)) {
            Assert::that($data['arguments'])
                ->isArray('"params.arguments" must be an object, {type} given.')
                ->isMap('"params.arguments" must be a string-keyed object.')
                ->values()->isString('"params.arguments" value must be a string, {type} given.')
            ;
            $arguments = $data['arguments'];
        }

        $inputResponses = null;

        if (\array_key_exists('inputResponses', $data)) {
            Assert::that($data['inputResponses'])
                ->isArray('"params.inputResponses" must be an object, {type} given.')
                ->isMap('"params.inputResponses" must be a string-keyed object.')
                ->values()
                ->isArray('each "params.inputResponses" entry must be an object, {type} given.')
                ->isMap('each "params.inputResponses" entry must be a string-keyed object.')
            ;
            $inputResponses = array_map(ElicitResult::fromArray(...), $data['inputResponses']);
        }

        $requestState = null;

        if (\array_key_exists('requestState', $data)) {
            Assert::that($data['requestState'])->isString('"params.requestState" must be a string, {type} given.');
            $requestState = $data['requestState'];
        }

        Assert::that($data)->hasOffset('_meta', '"params" is missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->isMap('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(
            name: $name,
            meta: $meta,
            arguments: $arguments,
            inputResponses: $inputResponses,
            requestState: $requestState,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            '_meta' => $this->meta->toArray(),
            'name' => $this->name,
        ];

        if (null !== $this->arguments && [] !== $this->arguments) {
            $data['arguments'] = $this->arguments;
        }

        if (null !== $this->inputResponses) {
            $data['inputResponses'] = array_map(
                static fn(InputResponse $response): array => $response->toArray(),
                $this->inputResponses,
            );
        }

        if (null !== $this->requestState) {
            $data['requestState'] = $this->requestState;
        }

        return $data;
    }
}
