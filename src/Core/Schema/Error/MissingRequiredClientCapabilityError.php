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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;

/**
 * Returned when processing a request requires a capability the client did not declare in
 * `clientCapabilities`. For HTTP, the response status code MUST be `400 Bad Request`.
 *
 * @extends Error<array{
 *   code: -32021,
 *   message: non-empty-string,
 *   data: array{requiredCapabilities: template-type<ClientCapabilities, Arrayable, 'T'>},
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#missingrequiredclientcapabilityerror
 */
final readonly class MissingRequiredClientCapabilityError extends Error
{
    public const string DEFAULT_MESSAGE = 'Missing required client capability';

    public function __construct(public ClientCapabilities $requiredCapabilities, string $message = self::DEFAULT_MESSAGE)
    {
        parent::__construct(
            code: ProtocolErrorCode::MissingRequiredClientCapability,
            message: $message,
            data: ['requiredCapabilities' => $requiredCapabilities->toArray()],
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

        Assert::that($payload)->hasOffset('requiredCapabilities', 'error "data" is missing the required "requiredCapabilities" key.');
        Assert::that($payload['requiredCapabilities'])
            ->isArray('error "data.requiredCapabilities" must be an object, {type} given.')
            ->isMap('error "data.requiredCapabilities" must be a string-keyed object.')
        ;

        return new self(
            requiredCapabilities: ClientCapabilities::fromArray($payload['requiredCapabilities']),
            message: $message,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'code' => ProtocolErrorCode::MissingRequiredClientCapability->value,
            'message' => $this->message,
            'data' => ['requiredCapabilities' => $this->requiredCapabilities->toArray()],
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'code' => ProtocolErrorCode::MissingRequiredClientCapability->value,
            'message' => $this->message,
            'data' => ['requiredCapabilities' => $this->requiredCapabilities->jsonSerialize()],
        ];
    }
}
