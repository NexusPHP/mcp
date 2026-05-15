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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;

/**
 * @implements Arrayable<array{
 *   code: int,
 *   message: non-empty-string,
 *   data?: array<string, mixed>
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#error
 */
abstract readonly class Error implements Arrayable
{
    public int $code;

    /**
     * @var non-empty-string
     */
    public string $message;

    /**
     * @param null|array<string, mixed> $data
     */
    public function __construct(
        ProtocolErrorCode $code,
        string $message,
        public ?array $data = null,
    ) {
        Assert::that($message)->isNonEmptyString('Error message must be a non-empty string.');

        $this->code = $code->value;
        $this->message = $message;
    }

    /**
     * Builds the standard `Error` subclass for the given protocol code.
     *
     * @param null|array<string, mixed> $data
     *
     * @throws \InvalidArgumentException When `$code` is `UrlElicitationRequired`
     */
    public static function forCode(ProtocolErrorCode $code, string $message, ?array $data = null): self
    {
        return match ($code) {
            ProtocolErrorCode::ParseError => new ParseError($message, $data),
            ProtocolErrorCode::InvalidRequest => new InvalidRequestError($message, $data),
            ProtocolErrorCode::MethodNotFound => new MethodNotFoundError($message, $data),
            ProtocolErrorCode::InvalidParams => new InvalidParamsError($message, $data),
            ProtocolErrorCode::InternalError => new InternalError($message, $data),
            ProtocolErrorCode::UrlElicitationRequired => throw new \InvalidArgumentException(
                'Error::forCode() cannot construct UrlElicitationRequiredErrorPayload; instantiate it directly with the required url/urlError payload.',
            ),
        };
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (null !== $this->data && [] !== $this->data) {
            $result['data'] = $this->data;
        }

        return $result;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
