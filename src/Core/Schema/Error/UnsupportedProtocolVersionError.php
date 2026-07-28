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

namespace Nexus\Mcp\Core\Schema\Error;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;

/**
 * Returned when the request's protocol version is unknown to the server or unsupported (e.g., a known
 * experimental or draft version the server has chosen not to implement). For HTTP, the response status
 * code MUST be `400 Bad Request`.
 *
 * @extends Error<array{
 *   code: -32022,
 *   message: non-empty-string,
 *   data: array{supported: list<string>, requested: string},
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#unsupportedprotocolversionerror
 */
final readonly class UnsupportedProtocolVersionError extends Error
{
    public const string DEFAULT_MESSAGE = 'Unsupported protocol version';

    /**
     * @var list<string>
     */
    public array $supported;

    /**
     * @param list<string> $supported
     */
    public function __construct(
        public string $requested,
        array $supported,
        string $message = self::DEFAULT_MESSAGE,
    ) {
        $this->supported = $supported;

        parent::__construct(
            code: ProtocolErrorCode::UnsupportedProtocolVersion,
            message: $message,
            data: ['supported' => $supported, 'requested' => $requested],
        );
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $message = $data['message'] ?? self::DEFAULT_MESSAGE;
        Assert::that($message)->isString('error "message" must be a string, {type} given.');

        $payload = $data['data'] ?? null;
        Assert::that($payload)
            ->isArray('error "data" must be an object, {type} given.')
            ->isMap('error "data" must be a string-keyed object.')
        ;

        Assert::that($payload)->hasOffset('requested', 'error "data" is missing the required "requested" key.');
        Assert::that($payload['requested'])->isString('error "data.requested" must be a string, {type} given.');
        $requested = $payload['requested'];

        Assert::that($payload)->hasOffset('supported', 'error "data" is missing the required "supported" key.');
        Assert::that($payload['supported'])
            ->isList('error "data.supported" must be a list of strings, {type} given.')
            ->values()->isString('each error "data.supported" must be a string, {type} given.')
        ;
        $supported = $payload['supported'];

        return new self(requested: $requested, supported: $supported, message: $message);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'code' => ProtocolErrorCode::UnsupportedProtocolVersion->value,
            'message' => $this->message,
            'data' => ['supported' => $this->supported, 'requested' => $this->requested],
        ];
    }
}
