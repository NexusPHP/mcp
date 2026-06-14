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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Request params that may carry the client's responses to a prior
 * `InputRequiredResult` plus the opaque request-state continuation token.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends RequestParams<T>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#inputresponserequestparams
 */
abstract readonly class InputResponseRequestParams extends RequestParams
{
    /**
     * @var null|array<string, array<string, mixed>>
     */
    public ?array $inputResponses;

    /**
     * @param null|array<string, array<string, mixed>> $inputResponses
     */
    public function __construct(
        ?array $inputResponses,
        public ?string $requestState,
        RequestMetaObject $meta,
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

        parent::__construct(meta: $meta);
    }
}
