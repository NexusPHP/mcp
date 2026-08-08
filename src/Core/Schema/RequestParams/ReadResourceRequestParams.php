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

/**
 * Parameters for a `resources/read` request.
 *
 * @extends ResourceRequestParams<array{
 *   _meta: template-type<RequestMetaObject, MetaObject, 'T'>,
 *   uri: string,
 *   inputResponses?: array<int|non-empty-string, array<string, mixed>>,
 *   requestState?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#readresourcerequestparams
 */
final readonly class ReadResourceRequestParams extends ResourceRequestParams implements InputResponseCarrierInterface
{
    /**
     * @var null|array<int|non-empty-string, InputResponse>
     */
    public ?array $inputResponses;

    /**
     * @param null|array<int|non-empty-string, InputResponse> $inputResponses
     */
    public function __construct(
        string $uri,
        RequestMetaObject $meta,
        ?array $inputResponses = null,
        public ?string $requestState = null,
    ) {
        $inputResponses = [] === $inputResponses ? null : $inputResponses;

        if (null !== $inputResponses) {
            Assert::that($inputResponses)
                ->keys()
                ->isIntOrNonEmptyString('each "params.inputResponses" key must be an int or non-empty string.')
            ;
            Assert::that($inputResponses)
                ->values()
                ->isInstanceOf(InputResponse::class, 'each "params.inputResponses" entry must be an InputResponse, {type} given.')
            ;
        }

        $this->inputResponses = $inputResponses;

        parent::__construct(uri: $uri, meta: $meta);
    }

    #[\Override]
    public function getInputResponses(): ?array
    {
        return $this->inputResponses;
    }

    #[\Override]
    public function getRequestState(): ?string
    {
        return $this->requestState;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', '"params" is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('"params.uri" must be a non-empty string, {type} given.');

        $inputResponses = null;

        if (\array_key_exists('inputResponses', $data)) {
            Assert::that($data['inputResponses'])
                ->isArray('"params.inputResponses" must be an object, {type} given.')
                ->keys()->isIntOrNonEmptyString('each "params.inputResponses" key must be an int or non-empty string.')
            ;
            Assert::that($data['inputResponses'])
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
            ->not()->isNonEmptyList('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(uri: $uri, meta: $meta, inputResponses: $inputResponses, requestState: $requestState);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            '_meta' => $this->meta->toArray(),
            'uri' => $this->uri,
        ];

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

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        if (null !== $this->inputResponses) {
            $data['inputResponses'] = array_map(
                static fn(InputResponse $response): array|\stdClass => $response->jsonSerialize(),
                $this->inputResponses,
            );

            if (array_is_list($this->inputResponses)) {
                $data['inputResponses'] = (object) $data['inputResponses'];
            }
        }

        return $data;
    }
}
