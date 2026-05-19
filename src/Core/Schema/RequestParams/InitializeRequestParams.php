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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Parameters for an `initialize` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#initializerequestparams
 */
final readonly class InitializeRequestParams extends RequestParams
{
    public function __construct(
        public ProtocolVersion $protocolVersion,
        public ClientCapabilities $capabilities,
        public Implementation $clientInfo,
        RequestMetaObject $meta = new RequestMetaObject(),
    ) {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('protocolVersion', 'missing the required "protocolVersion" key.');
        $protocolVersion = $data['protocolVersion'];
        Assert::that($protocolVersion)->isString('"params.protocolVersion" must be a string, {type} given.');

        Assert::that($data)->hasOffset('capabilities', 'missing the required "capabilities" key.');
        Assert::that($data['capabilities'])
            ->isArray('"params.capabilities" must be an object, {type} given.')
            ->isMap('"params.capabilities" must be a string-keyed object.')
        ;

        Assert::that($data)->hasOffset('clientInfo', 'missing the required "clientInfo" key.');
        Assert::that($data['clientInfo'])
            ->isArray('"params.clientInfo" must be an object, {type} given.')
            ->isMap('"params.clientInfo" must be a string-keyed object.')
        ;

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

        return new self(
            new ProtocolVersion($protocolVersion),
            ClientCapabilities::fromArray($data['capabilities']),
            Implementation::fromArray($data['clientInfo']),
            $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->toArray(),
            'clientInfo' => $this->clientInfo->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            ...parent::toArray(),
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->jsonSerialize(),
            'clientInfo' => $this->clientInfo->jsonSerialize(),
        ];
    }
}
