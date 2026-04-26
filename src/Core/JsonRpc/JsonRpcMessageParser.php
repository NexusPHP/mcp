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
use Nexus\Mcp\Core\Exception\JsonRpcParserException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;

/**
 * Parses wire data into concrete JSON-RPC message objects.
 *
 * The single entry point {@see self::parse()} dispatches structurally:
 * `error` to an error response, `result` to a success response (which requires
 * the caller-supplied {@see Result} subclass since the wire carries no method
 * name), and `method` to a request or notification by id presence.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic
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
     * Caller-supplied maps merge over the spec defaults from {@see JsonRpcMethodRegistry}
     * with per-key precedence (caller wins). Two semantically distinct uses share the
     * same parameter:
     *
     * - **Override** a spec method: pass a key already in the registry (e.g.
     *   `'initialize' => MyInitializeRequest::class`) to swap the dispatch target.
     * - **Extend** with a vendor method: pass a key not in the registry (e.g.
     *   `'myorg/custom-tool' => MyCustomRequest::class`) to add a new dispatch entry.
     *
     * No validation distinguishes these cases, so a typo in an override key (e.g.
     * `'initiailze' => ...`) silently registers a dead method instead of overriding;
     * verify the key matches a {@see JsonRpcMethodRegistry} entry when overriding.
     *
     * @param array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>      $requests
     * @param array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>> $notifications
     */
    public function __construct(array $requests = [], array $notifications = [])
    {
        $this->requests = [...JsonRpcMethodRegistry::requests(), ...$requests];
        $this->notifications = [...JsonRpcMethodRegistry::notifications(), ...$notifications];
    }

    /**
     * @template T of Result = Result
     *
     * @param array<string, mixed> $message     decoded JSON-RPC envelope
     * @param null|class-string<T> $resultClass required when $message is a success response
     *
     * @return JsonRpcErrorResponse|JsonRpcNotification<non-empty-string>|JsonRpcRequest<non-empty-string>|JsonRpcResultResponse<T>
     *
     * @throws JsonRpcParserException
     */
    public function parse(array $message, ?string $resultClass = null): JsonRpcMessage
    {
        self::assertJsonRpcVersion($message);

        if (\array_key_exists('error', $message)) {
            try {
                return JsonRpcErrorResponse::fromArray($message);
            } catch (\InvalidArgumentException $e) {
                throw new JsonRpcParserException('Invalid error response: '.$e->getMessage());
            }
        }

        if (\array_key_exists('result', $message)) {
            if (null === $resultClass) {
                throw new JsonRpcParserException('Success response requires the expected Result class; pass it as the second argument to parse().');
            }

            try {
                Assert::that($message)->hasOffset('id', 'Success response must carry an "id".');
                Assert::that($message['id'])->isArrayKey('Response "id" must be int or string, {type} given.');

                Assert::that($message['result'])
                    ->isArray('Success response "result" must be an object, {type} given.')
                    ->isMap('Success response "result" must be a string-keyed object.')
                ;
            } catch (\InvalidArgumentException $e) {
                throw new JsonRpcParserException($e->getMessage());
            }

            try {
                $typed = $resultClass::fromArray($message['result']);
            } catch (\InvalidArgumentException $e) {
                throw new JsonRpcParserException(\sprintf('Invalid %s payload: %s', $resultClass, $e->getMessage()));
            }

            return new JsonRpcResultResponse(new RequestId($message['id']), $typed);
        }

        try {
            Assert::that($message)->hasOffset('method', 'Wire message must carry a "method" (request or notification), an "error" (error response), or a "result" (success response).');
            Assert::that($message['method'])->isNonEmptyString('Wire "method" must be a non-empty string, {type} given.');
        } catch (\InvalidArgumentException $e) {
            throw new JsonRpcParserException($e->getMessage());
        }

        $method = $message['method'];

        if (\array_key_exists('id', $message)) {
            $class = $this->requests[$method] ?? null;

            if (null === $class) {
                throw new JsonRpcParserException(\sprintf('No request class registered for method "%s".', $method));
            }

            try {
                return $class::fromArray($message);
            } catch (\InvalidArgumentException $e) {
                throw new JsonRpcParserException(\sprintf('Invalid "%s" request: %s', $method, $e->getMessage()));
            }
        }

        $class = $this->notifications[$method] ?? null;

        if (null === $class) {
            throw new JsonRpcParserException(\sprintf('No notification class registered for method "%s".', $method));
        }

        try {
            return $class::fromArray($message);
        } catch (\InvalidArgumentException $e) {
            throw new JsonRpcParserException(\sprintf('Invalid "%s" notification: %s', $method, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function assertJsonRpcVersion(array $message): void
    {
        $version = $message['jsonrpc'] ?? null;

        if (JsonRpcMessage::JSONRPC_VERSION !== $version) {
            throw new JsonRpcParserException(\sprintf(
                'Invalid JSON-RPC version: expected "%s", got %s.',
                JsonRpcMessage::JSONRPC_VERSION,
                var_export($version, true),
            ));
        }
    }
}
