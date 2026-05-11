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
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * A response to a request that indicates an error occurred.
 *
 * @implements Arrayable<array{
 *   jsonrpc: '2.0',
 *   id?: int|non-empty-string,
 *   error: template-type<Error, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#jsonrpcerrorresponse
 */
final readonly class JsonRpcErrorResponse implements Arrayable, JsonRpcResponse
{
    public function __construct(public ?RequestId $id, public Error $error)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $data += ['id' => null, 'error' => []];

        $id = $data['id'];

        Assert::that($id)
            ->nullOr()
            ->isArrayKey('JSON-RPC error response id must be int, string, or null; {type} given.')
        ;

        Assert::that($data['error'])
            ->isArray('JSON-RPC error response "error" must be an object, {type} given.')
            ->isMap('JSON-RPC error response "error" must be a string-keyed object.')
        ;

        return new self(
            null === $id ? null : new RequestId($id),
            self::errorFromArray($data['error']),
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

    /**
     * @return array{
     *   jsonrpc: '2.0',
     *   id?: int|non-empty-string,
     *   error: template-type<Error, Arrayable, 'T'>,
     * }
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function errorFromArray(array $data): Error
    {
        $code = $data['code'] ?? null;
        Assert::that($code)->isInt('JSON-RPC error "code" must be an integer, {type} given.');

        $message = $data['message'] ?? null;
        Assert::that($message)->nullOr()->isString('JSON-RPC error "message" must be a string, {type} given.');

        $extra = null;

        if (\array_key_exists('data', $data)) {
            Assert::that($data['data'])
                ->isArray('JSON-RPC error "data" must be an object, {type} given.')
                ->isMap('JSON-RPC error "data" must be a string-keyed object.')
            ;
            $extra = $data['data'];
        }

        $narrow = [];

        if (null !== $message) {
            $narrow['message'] = $message;
        }

        if (null !== $extra) {
            $narrow['data'] = $extra;
        }

        return match (ProtocolErrorCode::tryFrom($code)) {
            ProtocolErrorCode::ParseError => ParseError::fromArray($narrow),
            ProtocolErrorCode::InvalidRequest => InvalidRequestError::fromArray($narrow),
            ProtocolErrorCode::MethodNotFound => MethodNotFoundError::fromArray($narrow),
            ProtocolErrorCode::InvalidParams => InvalidParamsError::fromArray($narrow),
            ProtocolErrorCode::UrlElicitationRequired => UrlElicitationRequiredErrorPayload::fromArray($narrow),
            default => InternalError::fromArray($narrow),
        };
    }
}
