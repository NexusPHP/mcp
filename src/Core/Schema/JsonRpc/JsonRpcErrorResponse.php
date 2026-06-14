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
use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * A response to a request that indicates an error occurred.
 *
 * @implements Arrayable<array{
 *   jsonrpc: '2.0',
 *   id?: int|non-empty-string,
 *   error: template-type<Error<array<string, mixed>>, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#jsonrpcerrorresponse
 */
final readonly class JsonRpcErrorResponse implements Arrayable, JsonRpcResponse
{
    /**
     * @param Error<array<string, mixed>> $error
     */
    public function __construct(public ?RequestId $id, public Error $error)
    {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;
        Assert::that($id)
            ->nullOr()
            ->isArrayKey('"id" must be an int, string, or null, {type} given.')
        ;

        $error = $data['error'] ?? [];
        Assert::that($error)
            ->isArray('"error" must be an object, {type} given.')
            ->isMap('"error" must be a string-keyed object.')
        ;

        return new self(
            id: null === $id ? null : new RequestId(id: $id),
            error: self::parseError($error),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $envelope = ['jsonrpc' => self::JSONRPC_VERSION];

        if (null !== $this->id) {
            $envelope['id'] = $this->id->id;
        }

        $envelope['error'] = $this->error->toArray();

        return $envelope;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Error<array<string, mixed>>
     */
    private static function parseError(array $data): Error
    {
        Assert::that($data)->hasOffset('code', '"error" is missing the required "code" key.');
        Assert::that($data['code'])->isInt('"error.code" must be an integer, {type} given.');
        $code = $data['code'];

        Assert::that($data)->hasOffset('message', '"error" is missing the required "message" key.');
        Assert::that($data['message'])->isString('"error.message" must be a string, {type} given.');
        $message = $data['message'];

        $extra = $data['data'] ?? null;
        $narrow = ['message' => $message];

        if (null !== $extra) {
            $narrow['data'] = $extra;
        }

        $resolved = ProtocolErrorCode::tryFrom($code);

        if (ProtocolErrorCode::UrlElicitationRequired === $resolved) {
            return UrlElicitationRequiredErrorPayload::fromArray($narrow);
        }

        if (null === $resolved) {
            return new UnknownProtocolError(code: $code, message: $message, data: $extra);
        }

        return ErrorFactory::create($resolved, $message, $extra);
    }
}
