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
use Nexus\Mcp\Core\Schema\Internal\RequestParams;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestMeta;

/**
 * Parameters for an `initialize` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle
 */
final readonly class InitializeRequestParams extends RequestParams
{
    public function __construct(
        public ProtocolVersion $protocolVersion,
        public ClientCapabilities $capabilities,
        public Implementation $clientInfo,
        ?RequestMeta $meta = null,
    ) {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('protocolVersion', 'InitializeRequestParams wire data missing "protocolVersion".');
        $protocolVersion = $data['protocolVersion'];
        Assert::that($protocolVersion)->isString('InitializeRequestParams wire "protocolVersion" must be a string, {type} given.');

        Assert::that($data)->hasOffset('capabilities', 'InitializeRequestParams wire data missing "capabilities".');
        Assert::that($data['capabilities'])
            ->isArray('InitializeRequestParams wire "capabilities" must be an object, {type} given.')
            ->isMap('InitializeRequestParams wire "capabilities" must be a string-keyed object.')
        ;

        Assert::that($data)->hasOffset('clientInfo', 'InitializeRequestParams wire data missing "clientInfo".');
        Assert::that($data['clientInfo'])
            ->isArray('InitializeRequestParams wire "clientInfo" must be an object, {type} given.')
            ->isMap('InitializeRequestParams wire "clientInfo" must be a string-keyed object.')
        ;

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMeta::fromArray($data['_meta']);
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
        return array_merge(parent::toArray(), [
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->toArray(),
            'clientInfo' => $this->clientInfo->toArray(),
        ]);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return array_merge(parent::toArray(), [
            'protocolVersion' => $this->protocolVersion->version,
            'capabilities' => $this->capabilities->jsonSerialize(),
            'clientInfo' => $this->clientInfo->jsonSerialize(),
        ]);
    }
}
