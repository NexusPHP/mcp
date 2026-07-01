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

namespace Nexus\Mcp\Core\Http;

use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Core\Schema\RequestMetaObject;

/**
 * Cross-checks the standard request headers (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`) against
 * the request body.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http#request-metadata
 */
final class StandardHeaderValidator
{
    private const string PROTOCOL_VERSION_HEADER = 'mcp-protocol-version';
    private const string METHOD_HEADER = 'mcp-method';
    private const string NAME_HEADER = 'mcp-name';

    /**
     * Validates the standard headers a request carries against its body. Returns the mismatch to reject
     * with, or null when the headers agree.
     *
     * @param array<string, string> $headers Header lines keyed by header name (matched case-insensitively)
     * @param array<string, mixed>  $body    Decoded JSON-RPC request envelope
     */
    public static function validate(array $headers, array $body): ?HeaderMismatchError
    {
        $headers = array_change_key_case($headers, \CASE_LOWER);

        return self::checkVersion($headers, $body)
            ?? self::checkMethod($headers, $body)
            ?? self::checkName($headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private static function checkVersion(array $headers, array $body): ?HeaderMismatchError
    {
        $header = $headers[self::PROTOCOL_VERSION_HEADER] ?? null;

        if (null === $header) {
            return new HeaderMismatchError('The MCP-Protocol-Version header is required but absent.');
        }

        $bodyVersion = self::readString($body, 'params', '_meta', RequestMetaObject::PROTOCOL_VERSION_KEY);

        if (null !== $bodyVersion && $header !== $bodyVersion) {
            return new HeaderMismatchError('The MCP-Protocol-Version header does not match the request body protocol version.');
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private static function checkMethod(array $headers, array $body): ?HeaderMismatchError
    {
        $header = $headers[self::METHOD_HEADER] ?? null;

        if (null === $header) {
            return new HeaderMismatchError('The Mcp-Method header is required but absent.');
        }

        $bodyMethod = self::readString($body, 'method');

        if (null !== $bodyMethod && $header !== $bodyMethod) {
            return new HeaderMismatchError('The Mcp-Method header does not match the request body method.');
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private static function checkName(array $headers, array $body): ?HeaderMismatchError
    {
        $field = match (self::readString($body, 'method')) {
            'tools/call', 'prompts/get' => 'name',
            'resources/read' => 'uri',
            default => null,
        };

        if (null === $field) {
            return null;
        }

        $source = self::readString($body, 'params', $field);
        $header = $headers[self::NAME_HEADER] ?? null;

        if (null === $header) {
            // A missing source value is a params fault. Only reject when the body actually carries one.
            return null === $source
                ? null
                : new HeaderMismatchError('The Mcp-Name header is required but absent.');
        }

        $decoded = HeaderValueCodec::decode($header);

        if (null === $decoded) {
            return new HeaderMismatchError('The Mcp-Name header is not a valid encoded value.');
        }

        if (null !== $source && $decoded !== $source) {
            return new HeaderMismatchError('The Mcp-Name header does not match the request body.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function readString(array $body, string ...$path): ?string
    {
        $node = $body;

        foreach ($path as $key) {
            if (! \is_array($node) || ! \array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return \is_string($node) ? $node : null;
    }
}
