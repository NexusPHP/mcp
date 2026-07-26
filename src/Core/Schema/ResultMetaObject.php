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

/**
 * Extends `MetaObject` with additional result-specific fields.
 * All key naming rules from `MetaObject` apply.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#resultmetaobject
 */
final readonly class ResultMetaObject implements Arrayable
{
    public const string SERVER_INFO_KEY = 'io.modelcontextprotocol/serverInfo';

    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(public ?Implementation $serverInfo = null, public array $extras = [])
    {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $serverInfo = null;

        if (\array_key_exists(self::SERVER_INFO_KEY, $data)) {
            Assert::that($data[self::SERVER_INFO_KEY])
                ->isArray(\sprintf('"_meta.%s" must be an object, {type} given.', self::SERVER_INFO_KEY))
                ->isMap(\sprintf('"_meta.%s" must be a string-keyed object.', self::SERVER_INFO_KEY))
            ;
            $serverInfo = Implementation::fromArray($data[self::SERVER_INFO_KEY]);
            unset($data[self::SERVER_INFO_KEY]);
        }

        return new self(serverInfo: $serverInfo, extras: $data);
    }

    /**
     * Whether a server identity is present, in the typed slot or among the extras
     * a directly constructed instance may still carry it in.
     *
     * @internal
     */
    public function declaresServerInfo(): bool
    {
        return null !== $this->serverInfo || \array_key_exists(self::SERVER_INFO_KEY, $this->extras);
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];

        if (null !== $this->serverInfo) {
            $out[self::SERVER_INFO_KEY] = $this->serverInfo->toArray();
        }

        $out += $this->extras;

        return $out;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $out = $this->toArray();

        return [] === $out ? new \stdClass() : $out;
    }
}
