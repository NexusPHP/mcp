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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result;

/**
 * An InputRequiredResult sent by the server to indicate that additional input is needed
 * before the request can be completed.
 *
 * At least one of `inputRequests` or `requestState` MUST be present.
 *
 * @extends Result<array{
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 *   inputRequests?: array<string, array<string, mixed>>,
 *   requestState?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#inputrequiredresult
 */
final readonly class InputRequiredResult extends Result implements ServerResult
{
    /**
     * @var null|array<string, InputRequest>
     */
    public ?array $inputRequests;

    /**
     * @param null|array<string, InputRequest> $inputRequests
     */
    public function __construct(
        ?array $inputRequests = null,
        public ?string $requestState = null,
        MetaObject $meta = new MetaObject(),
    ) {
        $inputRequests = [] === $inputRequests ? null : $inputRequests;

        if (null === $inputRequests && null === $requestState) {
            throw new \InvalidArgumentException('"result" must carry at least one of "inputRequests" or "requestState".');
        }

        if (null !== $inputRequests) {
            Assert::that($inputRequests)
                ->isMap('"result.inputRequests" must be a string-keyed object.')
                ->values()
                ->isInstanceOf(InputRequest::class, 'each "result.inputRequests" entry must be an InputRequest, {type} given.')
            ;
        }

        $this->inputRequests = $inputRequests;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $inputRequests = null;

        if (\array_key_exists('inputRequests', $data)) {
            Assert::that($data['inputRequests'])
                ->isArray('"result.inputRequests" must be an object, {type} given.')
                ->isMap('"result.inputRequests" must be a string-keyed object.')
                ->values()
                ->isArray('each "result.inputRequests" entry must be an object, {type} given.')
                ->isMap('each "result.inputRequests" entry must be a string-keyed object.')
            ;
            $inputRequests = array_map(self::decodeInputRequest(...), $data['inputRequests']);
        }

        $requestState = null;

        if (\array_key_exists('requestState', $data)) {
            Assert::that($data['requestState'])->isString('"result.requestState" must be a string, {type} given.');
            $requestState = $data['requestState'];
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(inputRequests: $inputRequests, requestState: $requestState, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();

        if (null !== $this->inputRequests) {
            $data['inputRequests'] = array_map(
                static fn(InputRequest $request): array => $request->toArray(),
                $this->inputRequests,
            );
        }

        if (null !== $this->requestState) {
            $data['requestState'] = $this->requestState;
        }

        return $data;
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::InputRequired->value;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function decodeInputRequest(array $request): InputRequest
    {
        Assert::that($request)->hasOffset('method', 'each "result.inputRequests" entry is missing the required "method" key.');

        return match ($request['method']) {
            'elicitation/create' => ElicitRequest::fromArray($request),
            default => throw new \InvalidArgumentException(\sprintf(
                'each "result.inputRequests" entry must use a supported input-request method, %s given.',
                var_export($request['method'], true),
            )),
        };
    }
}
