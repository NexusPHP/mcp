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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\ServerCapabilities;

/**
 * After receiving an initialize request from the client, the server sends this response.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#initializeresult
 */
final readonly class InitializeResult extends Result implements ServerResult
{
    /**
     * @var null|non-empty-string
     */
    public ?string $instructions;

    public function __construct(
        public ProtocolVersion $protocolVersion,
        public ServerCapabilities $capabilities,
        public Implementation $serverInfo,
        ?string $instructions = null,
        ?MetaObject $meta = null,
    ) {
        Assert::that($instructions)->nullOr()->isNonEmptyString('InitializeResult instructions must be a non-empty string or null.');

        $this->instructions = $instructions;

        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('protocolVersion', 'InitializeResult data missing "protocolVersion".');
        $protocolVersion = $data['protocolVersion'];
        Assert::that($protocolVersion)->isString('InitializeResult "protocolVersion" must be a string, {type} given.');

        Assert::that($data)->hasOffset('capabilities', 'InitializeResult data missing "capabilities".');
        Assert::that($data['capabilities'])
            ->isArray('InitializeResult "capabilities" must be an object, {type} given.')
            ->isMap('InitializeResult "capabilities" must be a string-keyed object.')
        ;

        Assert::that($data)->hasOffset('serverInfo', 'InitializeResult data missing "serverInfo".');
        Assert::that($data['serverInfo'])
            ->isArray('InitializeResult "serverInfo" must be an object, {type} given.')
            ->isMap('InitializeResult "serverInfo" must be a string-keyed object.')
        ;

        $instructions = $data['instructions'] ?? null;
        Assert::that($instructions)->nullOr()->isString('InitializeResult "instructions" must be a string or null, {type} given.');

        $meta = MetaObject::parseFrom($data, 'Result');

        return new self(
            new ProtocolVersion($protocolVersion),
            ServerCapabilities::fromArray($data['capabilities']),
            Implementation::fromArray($data['serverInfo']),
            $instructions,
            $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->toArray(),
            'serverInfo' => $this->serverInfo->toArray(),
        ];

        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = [
            ...parent::toArray(),
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->jsonSerialize(),
            'serverInfo' => $this->serverInfo->jsonSerialize(),
        ];

        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }

        return $data;
    }
}
