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
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;

/**
 * Builder and validator for the standard request headers (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`).
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#request-metadata
 */
final class StandardHeaders
{
    private const string PROTOCOL_VERSION_HEADER = 'MCP-Protocol-Version';
    private const string METHOD_HEADER = 'Mcp-Method';
    private const string NAME_HEADER = 'Mcp-Name';

    /**
     * Builds the standard headers mirroring an outbound request envelope, omitting one whose source value
     * the body does not carry.
     *
     * @param array<string, mixed> $body
     *
     * @return array<non-empty-string, string>
     */
    public static function build(array $body): array
    {
        $headers = [];
        $version = self::readVersion($body);

        if (null !== $version) {
            $headers[self::PROTOCOL_VERSION_HEADER] = $version;
        }

        $method = self::readString($body, 'method');

        if (null !== $method) {
            $headers[self::METHOD_HEADER] = $method;
        }

        $source = self::readName($body);

        if (null !== $source) {
            $headers[self::NAME_HEADER] = HeaderValueCodec::encode($source);
        }

        return $headers;
    }

    /**
     * Validates the standard headers a request carries against its body, returning the mismatch to reject
     * with or null when they agree.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
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
        $header = self::readLine($headers, self::PROTOCOL_VERSION_HEADER);

        if (null === $header) {
            return new HeaderMismatchError('The MCP-Protocol-Version header is required but absent.');
        }

        $bodyVersion = self::readVersion($body);

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
        $header = self::readLine($headers, self::METHOD_HEADER);

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
        if (self::resolveNameField($body) === null) {
            return null;
        }

        $source = self::readName($body);
        $header = self::readLine($headers, self::NAME_HEADER);

        if (null === $header) {
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
     * @param array<string, string> $headers Keys already lowercased
     */
    private static function readLine(array $headers, string $name): ?string
    {
        return $headers[strtolower($name)] ?? null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function readVersion(array $body): ?string
    {
        return self::readString($body, 'params', '_meta', RequestMetaObject::PROTOCOL_VERSION_KEY);
    }

    /**
     * The body value `Mcp-Name` mirrors, or null when the method carries none.
     *
     * @param array<string, mixed> $body
     */
    private static function readName(array $body): ?string
    {
        $field = self::resolveNameField($body);

        return null === $field ? null : self::readString($body, 'params', $field);
    }

    /**
     * The `params` key `Mcp-Name` mirrors for the body's method.
     *
     * @param array<string, mixed> $body
     */
    private static function resolveNameField(array $body): ?string
    {
        return match (self::readString($body, 'method')) {
            'tools/call', 'prompts/get' => 'name',
            'resources/read' => 'uri',
            'tasks/get', 'tasks/update', 'tasks/cancel' => 'taskId',
            default => null,
        };
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
