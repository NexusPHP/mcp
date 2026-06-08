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
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Extends `MetaObject` with additional request-specific fields.
 * All key naming rules from `MetaObject` apply.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#_meta
 */
final readonly class RequestMetaObject implements Arrayable
{
    public const string PROTOCOL_VERSION_KEY = 'io.modelcontextprotocol/protocolVersion';
    public const string CLIENT_INFO_KEY = 'io.modelcontextprotocol/clientInfo';
    public const string CLIENT_CAPABILITIES_KEY = 'io.modelcontextprotocol/clientCapabilities';
    public const string LOG_LEVEL_KEY = 'io.modelcontextprotocol/logLevel';

    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public ProtocolVersion $protocolVersion,
        public Implementation $clientInfo,
        public ClientCapabilities $clientCapabilities,
        public ?LoggingLevel $logLevel = null,
        public ?ProgressToken $progressToken = null,
        public array $extras = [],
    ) {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset(self::PROTOCOL_VERSION_KEY, '"_meta" missing the required "{key}" key.');
        $protocolVersion = $data[self::PROTOCOL_VERSION_KEY];
        Assert::that($protocolVersion)->isString(\sprintf('"_meta.%s" must be a string, {type} given.', self::PROTOCOL_VERSION_KEY));
        unset($data[self::PROTOCOL_VERSION_KEY]);

        Assert::that($data)->hasOffset(self::CLIENT_INFO_KEY, '"_meta" missing the required "{key}" key.');
        Assert::that($data[self::CLIENT_INFO_KEY])
            ->isArray(\sprintf('"_meta.%s" must be an object, {type} given.', self::CLIENT_INFO_KEY))
            ->isMap(\sprintf('"_meta.%s" must be a string-keyed object.', self::CLIENT_INFO_KEY))
        ;
        $clientInfo = Implementation::fromArray($data[self::CLIENT_INFO_KEY]);
        unset($data[self::CLIENT_INFO_KEY]);

        Assert::that($data)->hasOffset(self::CLIENT_CAPABILITIES_KEY, '"_meta" missing the required "{key}" key.');
        Assert::that($data[self::CLIENT_CAPABILITIES_KEY])
            ->isArray(\sprintf('"_meta.%s" must be an object, {type} given.', self::CLIENT_CAPABILITIES_KEY))
            ->isMap(\sprintf('"_meta.%s" must be a string-keyed object.', self::CLIENT_CAPABILITIES_KEY))
        ;
        $clientCapabilities = ClientCapabilities::fromArray($data[self::CLIENT_CAPABILITIES_KEY]);
        unset($data[self::CLIENT_CAPABILITIES_KEY]);

        $logLevel = null;

        if (\array_key_exists(self::LOG_LEVEL_KEY, $data)) {
            $logLevel = EnumValueValidator::parse(LoggingLevel::class, $data[self::LOG_LEVEL_KEY], \sprintf('"_meta.%s"', self::LOG_LEVEL_KEY));
            unset($data[self::LOG_LEVEL_KEY]);
        }

        $progressToken = null;

        if (\array_key_exists('progressToken', $data)) {
            $raw = $data['progressToken'];
            Assert::that($raw)->isArrayKey('"_meta.progressToken" must be an int or string, {type} given.');

            $progressToken = new ProgressToken($raw);
            unset($data['progressToken']);
        }

        return new self(
            new ProtocolVersion($protocolVersion),
            $clientInfo,
            $clientCapabilities,
            $logLevel,
            $progressToken,
            $data,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $out = $this->extras;

        $out[self::PROTOCOL_VERSION_KEY] = $this->protocolVersion->version;
        $out[self::CLIENT_INFO_KEY] = $this->clientInfo->toArray();
        $out[self::CLIENT_CAPABILITIES_KEY] = $this->clientCapabilities->toArray();

        if (null !== $this->progressToken) {
            $out['progressToken'] = $this->progressToken->token;
        }

        if (null !== $this->logLevel) {
            $out[self::LOG_LEVEL_KEY] = $this->logLevel->value;
        }

        return $out;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $out = $this->toArray();
        $out[self::CLIENT_CAPABILITIES_KEY] = $this->clientCapabilities->jsonSerialize();

        return $out;
    }
}
