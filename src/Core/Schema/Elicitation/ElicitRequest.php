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

namespace Nexus\Mcp\Core\Schema\Elicitation;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;

/**
 * A request from the server to elicit additional information from the user via the client.
 *
 * @property-read ElicitRequestFormParams|ElicitRequestUrlParams $params
 *
 * @extends Request<'elicitation/create'>
 * @implements InputRequest<array{
 *   method: 'elicitation/create',
 *   params: template-type<ElicitRequestFormParams|ElicitRequestUrlParams, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#elicitrequest
 */
final readonly class ElicitRequest extends Request implements InputRequest
{
    public function __construct(ElicitRequestFormParams|ElicitRequestUrlParams $params)
    {
        parent::__construct(params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'elicitation/create';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'elicit request is missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('elicit request "params" must be an object, {type} given.')
            ->isMap('elicit request "params" must be a string-keyed object.')
        ;

        $mode = $data['params']['mode'] ?? ElicitRequestFormParams::MODE;

        $params = ElicitRequestUrlParams::MODE === $mode
            ? ElicitRequestUrlParams::fromArray($data['params'])
            : ElicitRequestFormParams::fromArray($data['params']);

        return new self(params: $params);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'method' => static::getMethod(),
            'params' => $this->params->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'method' => static::getMethod(),
            'params' => $this->params->jsonSerialize(),
        ];
    }
}
