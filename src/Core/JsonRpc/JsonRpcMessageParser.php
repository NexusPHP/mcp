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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\InvalidRequestException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;

/**
 * Parses decoded JSON-RPC envelopes into concrete message objects.
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic
 */
final class JsonRpcMessageParser
{
    /**
     * @var array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    private readonly array $requests;

    /**
     * @var array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    private readonly array $notifications;

    /**
     * @param array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>      $requests      Merged over `JsonRpcMethodRegistry::requests()` with caller precedence.
     * @param array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>> $notifications Merged over `JsonRpcMethodRegistry::notifications()` with caller precedence.
     */
    public function __construct(array $requests = [], array $notifications = [])
    {
        $this->requests = [...JsonRpcMethodRegistry::requests(), ...$requests];
        $this->notifications = [...JsonRpcMethodRegistry::notifications(), ...$notifications];
    }

    /**
     * @template T of Result = Result
     *
     * @param array<string, mixed> $message Decoded JSON-RPC envelope
     * @param null|class-string<T> $result  When null, a success response envelope yields an `UnparsedResultEnvelope`
     *                                      carrying the raw payload. When supplied, it is decoded into `JsonRpcResultResponse<T>`.
     *
     * @return ($result is null
     *     ? JsonRpcErrorResponse|JsonRpcNotification<non-empty-string>|JsonRpcRequest<non-empty-string>|UnparsedResultEnvelope
     *     : JsonRpcErrorResponse|JsonRpcNotification<non-empty-string>|JsonRpcRequest<non-empty-string>|JsonRpcResultResponse<T>)
     *
     * @throws AbstractJsonRpcProtocolException
     */
    public function parse(array $message, ?string $result = null): JsonRpcMessage|UnparsedResultEnvelope
    {
        self::assertJsonRpcVersion($message);

        if (\array_key_exists('error', $message)) {
            try {
                return JsonRpcErrorResponse::fromArray($message);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidRequestException(
                    self::extractRequestId($message),
                    \sprintf('Invalid error response: %s', $e->getMessage()),
                );
            }
        }

        if (\array_key_exists('result', $message)) {
            try {
                Assert::that($message)->hasOffset('id', 'Success response must carry an "id".');
                Assert::that($message['id'])->isArrayKey('Response "id" must be an int or string, {type} given.');
                $id = new RequestId(id: $message['id']);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidRequestException(null, $e->getMessage());
            }

            if (null === $result) {
                return new UnparsedResultEnvelope($id, $message['result']);
            }

            try {
                Assert::that($message['result'])
                    ->isArray('Success response "result" must be an object, {type} given.')
                    ->isMap('Success response "result" must be a string-keyed object.')
                ;
            } catch (\InvalidArgumentException $e) {
                throw new InvalidRequestException($id, $e->getMessage());
            }

            try {
                $typed = $result::fromArray($message['result']);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidRequestException($id, \sprintf('Invalid %s payload: %s', $result, $e->getMessage()));
            }

            return new JsonRpcResultResponse(id: $id, result: $typed);
        }

        try {
            Assert::that($message)->hasOffset('method', 'JSON-RPC envelope must carry a "method" (request or notification), an "error" (error response), or a "result" (success response).');
            Assert::that($message['method'])->isNonEmptyString('JSON-RPC envelope "method" must be a non-empty string, {type} given.');
        } catch (\InvalidArgumentException $e) {
            throw new InvalidRequestException(self::extractRequestId($message), $e->getMessage());
        }

        $method = $message['method'];

        if (\array_key_exists('id', $message)) {
            try {
                Assert::that($message['id'])->isArrayKey('Request "id" must be an int or string, {type} given.');
                $id = new RequestId(id: $message['id']);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidRequestException(null, $e->getMessage());
            }

            $class = $this->requests[$method] ?? null;

            if (null === $class) {
                if (\array_key_exists($method, $this->notifications)) {
                    throw new MethodMisroutedException(
                        $method,
                        expectedShape: 'notification',
                        receivedShape: 'request',
                        requestId: $id,
                    );
                }

                throw new MethodNotFoundException($method, $id);
            }

            try {
                return $class::fromArray($message);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidParamsException(
                    $id,
                    \sprintf('Invalid "%s" request: %s', SafeDisplay::sanitise($method), $e->getMessage()),
                );
            }
        }

        $class = $this->notifications[$method] ?? null;

        if (null === $class) {
            if (\array_key_exists($method, $this->requests)) {
                throw new MethodMisroutedException(
                    $method,
                    expectedShape: 'request',
                    receivedShape: 'notification',
                );
            }

            throw new MethodNotFoundException($method);
        }

        try {
            return $class::fromArray($message);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidParamsException(
                null,
                \sprintf('Invalid "%s" notification: %s', SafeDisplay::sanitise($method), $e->getMessage()),
            );
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function assertJsonRpcVersion(array $message): void
    {
        $version = $message['jsonrpc'] ?? null;

        if (JsonRpcMessage::JSONRPC_VERSION !== $version) {
            throw new InvalidRequestException(
                self::extractRequestId($message),
                \sprintf(
                    'Invalid JSON-RPC version: expected "%s", got %s.',
                    JsonRpcMessage::JSONRPC_VERSION,
                    null === $version ? 'null' : SafeDisplay::sanitise(var_export($version, true)),
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function extractRequestId(array $message): ?RequestId
    {
        $id = $message['id'] ?? null;

        if (! \is_int($id) && ! \is_string($id)) {
            return null;
        }

        try {
            return new RequestId(id: $id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
