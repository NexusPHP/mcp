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

/**
 * @implements Arrayable<array{
 *   code: int,
 *   message: non-empty-string,
 *   data?: array<string, mixed>
 * }>
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

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (null !== $this->data) {
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
