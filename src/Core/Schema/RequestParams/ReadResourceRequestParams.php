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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\RequestMetaObject;

/**
 * Parameters for a `resources/read` request.
 *
 * @extends ResourceRequestParams<array{
 *   _meta: template-type<RequestMetaObject, Arrayable, 'T'>,
 *   uri: string,
 *   inputResponses?: array<string, array<string, mixed>>,
 *   requestState?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#readresourcerequestparams
 */
final readonly class ReadResourceRequestParams extends ResourceRequestParams
{
    /**
     * @var null|array<string, array<string, mixed>>
     */
    public ?array $inputResponses;

    /**
     * @param null|array<string, array<string, mixed>> $inputResponses
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
                ->isMap('"params.inputResponses" must be a string-keyed object.')
                ->values()
                ->isArray('each "params.inputResponses" entry must be an object, {type} given.')
                ->isMap('each "params.inputResponses" entry must be a string-keyed object.')
            ;
        }

        $this->inputResponses = $inputResponses;

        parent::__construct($uri, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('"params.uri" must be a string, {type} given.');

        $inputResponses = null;

        if (\array_key_exists('inputResponses', $data)) {
            Assert::that($data['inputResponses'])
                ->isArray('"params.inputResponses" must be an object, {type} given.')
                ->isMap('"params.inputResponses" must be a string-keyed object.')
                ->values()
                ->isArray('each "params.inputResponses" entry must be an object, {type} given.')
                ->isMap('each "params.inputResponses" entry must be a string-keyed object.')
            ;
            $inputResponses = $data['inputResponses'];
        }

        $requestState = null;

        if (\array_key_exists('requestState', $data)) {
            Assert::that($data['requestState'])->isString('"params.requestState" must be a string, {type} given.');
            $requestState = $data['requestState'];
        }

        Assert::that($data)->hasOffset('_meta', '"params" missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->isMap('"params._meta" must be a string-keyed object.')
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
            $data['inputResponses'] = $this->inputResponses;
        }

        if (null !== $this->requestState) {
            $data['requestState'] = $this->requestState;
        }

        return $data;
    }
}
