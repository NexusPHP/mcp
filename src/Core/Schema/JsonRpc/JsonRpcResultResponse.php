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

namespace Nexus\Mcp\Core\Schema\JsonRpc;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;

/**
 * A successful (non-error) response to a request.
 *
 * @template-covariant TEnvelope of array<string, mixed> = array<string, mixed>
 *
 * @implements Arrayable<TEnvelope>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#jsonrpcresultresponse
 */
abstract readonly class JsonRpcResultResponse implements Arrayable, JsonRpcResponse
{
    public function __construct(public RequestId $id, public Result $result)
    {
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'result' => $this->result->jsonSerialize(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    protected static function parseId(array $data): RequestId
    {
        Assert::that($data)->hasOffset('id', 'missing the required "id" key.');
        $id = $data['id'];
        Assert::that($id)->isIntOrNonEmptyString('"id" must be an int or non-empty string, {type} given.');

        return new RequestId(id: $id);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    protected static function parseResult(array $data): array
    {
        Assert::that($data)->hasOffset('result', 'missing the required "result" key.');
        $result = $data['result'];
        Assert::that($result)
            ->isArray('"result" must be an object, {type} given.')
            ->isMap('"result" must be a string-keyed object.')
        ;

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function isInputRequired(array $payload): bool
    {
        return ($payload['resultType'] ?? null) === ResultType::InputRequired->value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException
     */
    protected static function rejectInputRequired(array $payload): void
    {
        if (self::isInputRequired($payload)) {
            throw new \InvalidArgumentException('"result" returned "input_required" for a method that does not support it.');
        }
    }
}
