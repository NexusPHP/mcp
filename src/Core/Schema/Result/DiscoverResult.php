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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `server/discover` request.
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#discoverresult
 */
final readonly class DiscoverResult extends CacheableResult implements ServerResult
{
    /**
     * @var list<non-empty-string>
     */
    public array $supportedVersions;

    /**
     * @param list<string> $supportedVersions
     */
    public function __construct(
        array $supportedVersions,
        public ServerCapabilities $capabilities,
        public Implementation $serverInfo,
        int $ttlMs,
        CacheScope $cacheScope,
        public ?string $instructions = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($supportedVersions)
            ->isList('"result.supportedVersions" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each "result.supportedVersions" must be a non-empty string.')
        ;

        $this->supportedVersions = $supportedVersions;

        parent::__construct($ttlMs, $cacheScope, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('supportedVersions', '"result" missing the required "supportedVersions" key.');
        Assert::that($data['supportedVersions'])
            ->isList('"result.supportedVersions" must be a list, {type} given.')
            ->values()->isString('each "result.supportedVersions" must be a string, {type} given.')
        ;
        $supportedVersions = $data['supportedVersions'];

        Assert::that($data)->hasOffset('capabilities', '"result" missing the required "capabilities" key.');
        Assert::that($data['capabilities'])
            ->isArray('"result.capabilities" must be an object, {type} given.')
            ->isMap('"result.capabilities" must be a string-keyed object.')
        ;
        $capabilities = ServerCapabilities::fromArray($data['capabilities']);

        Assert::that($data)->hasOffset('serverInfo', '"result" missing the required "serverInfo" key.');
        Assert::that($data['serverInfo'])
            ->isArray('"result.serverInfo" must be an object, {type} given.')
            ->isMap('"result.serverInfo" must be a string-keyed object.')
        ;
        $serverInfo = Implementation::fromArray($data['serverInfo']);

        Assert::that($data)->hasOffset('ttlMs', '"result" missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $instructions = null;

        if (\array_key_exists('instructions', $data)) {
            $raw = $data['instructions'];
            Assert::that($raw)->isString('"result.instructions" must be a string, {type} given.');
            $instructions = $raw;
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(
            supportedVersions: $supportedVersions,
            capabilities: $capabilities,
            serverInfo: $serverInfo,
            ttlMs: $ttlMs,
            cacheScope: $cacheScope,
            instructions: $instructions,
            meta: $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'supportedVersions' => $this->supportedVersions,
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
        $data = $this->toArray();
        $data['capabilities'] = $this->capabilities->jsonSerialize();

        return $data;
    }
}
