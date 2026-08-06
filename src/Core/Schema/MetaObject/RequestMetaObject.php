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

namespace Nexus\Mcp\Core\Schema\MetaObject;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Extends `MetaObject` with additional request-specific fields.
 * All key naming rules from `MetaObject` apply.
 *
 * @extends MetaObject<array{
 *   'io.modelcontextprotocol/protocolVersion': non-empty-string,
 *   'io.modelcontextprotocol/clientInfo'?: template-type<Implementation, Arrayable, 'T'>,
 *   'io.modelcontextprotocol/clientCapabilities': template-type<ClientCapabilities, Arrayable, 'T'>,
 *   'io.modelcontextprotocol/logLevel'?: value-of<LoggingLevel>,
 *   progressToken?: int|non-empty-string,
 *   ...<string, mixed>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#requestmetaobject
 */
final readonly class RequestMetaObject extends MetaObject
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
        public ClientCapabilities $clientCapabilities,
        public ?Implementation $clientInfo = null,
        public ?LoggingLevel $logLevel = null,
        public ?ProgressToken $progressToken = null,
        array $extras = [],
    ) {
        parent::__construct(extras: $extras);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset(self::PROTOCOL_VERSION_KEY, '"_meta" is missing the required "{key}" key.');
        $protocolVersion = $data[self::PROTOCOL_VERSION_KEY];
        Assert::that($protocolVersion)->isNonEmptyString(\sprintf('"_meta.%s" must be a non-empty string, {type} given.', self::PROTOCOL_VERSION_KEY));
        unset($data[self::PROTOCOL_VERSION_KEY]);

        Assert::that($data)->hasOffset(self::CLIENT_CAPABILITIES_KEY, '"_meta" is missing the required "{key}" key.');
        Assert::that($data[self::CLIENT_CAPABILITIES_KEY])
            ->isArray(\sprintf('"_meta.%s" must be an object, {type} given.', self::CLIENT_CAPABILITIES_KEY))
            ->isMap(\sprintf('"_meta.%s" must be a string-keyed object.', self::CLIENT_CAPABILITIES_KEY))
        ;
        $clientCapabilities = ClientCapabilities::fromArray($data[self::CLIENT_CAPABILITIES_KEY]);
        unset($data[self::CLIENT_CAPABILITIES_KEY]);

        $clientInfo = null;

        if (\array_key_exists(self::CLIENT_INFO_KEY, $data)) {
            Assert::that($data[self::CLIENT_INFO_KEY])
                ->isArray(\sprintf('"_meta.%s" must be an object, {type} given.', self::CLIENT_INFO_KEY))
                ->isMap(\sprintf('"_meta.%s" must be a string-keyed object.', self::CLIENT_INFO_KEY))
            ;
            $clientInfo = Implementation::fromArray($data[self::CLIENT_INFO_KEY]);
            unset($data[self::CLIENT_INFO_KEY]);
        }

        $logLevel = null;

        if (\array_key_exists(self::LOG_LEVEL_KEY, $data)) {
            $logLevel = EnumValueValidator::parse(LoggingLevel::class, $data[self::LOG_LEVEL_KEY], \sprintf('"_meta.%s"', self::LOG_LEVEL_KEY));
            unset($data[self::LOG_LEVEL_KEY]);
        }

        $progressToken = null;

        if (\array_key_exists('progressToken', $data)) {
            $raw = $data['progressToken'];
            Assert::that($raw)->isIntOrNonEmptyString('"_meta.progressToken" must be an int or non-empty string, {type} given.');

            $progressToken = new ProgressToken(token: $raw);
            unset($data['progressToken']);
        }

        return new self(
            protocolVersion: new ProtocolVersion(version: $protocolVersion),
            clientCapabilities: $clientCapabilities,
            clientInfo: $clientInfo,
            logLevel: $logLevel,
            progressToken: $progressToken,
            extras: $data,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];

        $out[self::PROTOCOL_VERSION_KEY] = $this->protocolVersion->version;

        if (null !== $this->clientInfo) {
            $out[self::CLIENT_INFO_KEY] = $this->clientInfo->toArray();
        }

        $out[self::CLIENT_CAPABILITIES_KEY] = $this->clientCapabilities->toArray();

        if (null !== $this->logLevel) {
            $out[self::LOG_LEVEL_KEY] = $this->logLevel->value;
        }

        if (null !== $this->progressToken) {
            $out['progressToken'] = $this->progressToken->token;
        }

        $out += $this->extras;

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
