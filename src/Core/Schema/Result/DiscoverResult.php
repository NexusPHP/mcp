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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `server/discover` request.
 *
 * @extends CacheableResult<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   supportedVersions: list<non-empty-string>,
 *   capabilities: template-type<ServerCapabilities, Arrayable, 'T'>,
 *   instructions?: non-empty-string,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#discoverresult
 */
final readonly class DiscoverResult extends CacheableResult implements ServerResult
{
    /**
     * @param list<non-empty-string> $supportedVersions
     * @param null|non-empty-string  $instructions
     */
    public function __construct(
        public array $supportedVersions,
        public ServerCapabilities $capabilities,
        int $ttlMs,
        CacheScope $cacheScope,
        public ?string $instructions = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        Assert::that($supportedVersions)
            ->isList('"result.supportedVersions" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each "result.supportedVersions" must be a non-empty string.')
        ;
        Assert::that($instructions)->nullOr()->isNonEmptyString('"result.instructions" must be a non-empty string or null.');

        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('supportedVersions', '"result" is missing the required "supportedVersions" key.');
        Assert::that($data['supportedVersions'])
            ->isList('"result.supportedVersions" must be a list, {type} given.')
            ->values()->isNonEmptyString('each "result.supportedVersions" must be a non-empty string, {type} given.')
        ;
        $supportedVersions = $data['supportedVersions'];

        Assert::that($data)->hasOffset('capabilities', '"result" is missing the required "capabilities" key.');
        Assert::that($data['capabilities'])
            ->isArray('"result.capabilities" must be an object, {type} given.')
            ->isMap('"result.capabilities" must be a string-keyed object.')
        ;
        $capabilities = ServerCapabilities::fromArray($data['capabilities']);

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $instructions = null;

        if (\array_key_exists('instructions', $data)) {
            $raw = $data['instructions'];
            Assert::that($raw)->isNonEmptyString('"result.instructions" must be a non-empty string, {type} given.');
            $instructions = $raw;
        }

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(
            supportedVersions: $supportedVersions,
            capabilities: $capabilities,
            ttlMs: $ttlMs,
            cacheScope: $cacheScope,
            instructions: $instructions,
            meta: $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();
        $data['supportedVersions'] = $this->supportedVersions;
        $data['capabilities'] = $this->capabilities->toArray();

        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }

        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['capabilities'] = $this->capabilities->jsonSerialize();

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            supportedVersions: $this->supportedVersions,
            capabilities: $this->capabilities,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            instructions: $this->instructions,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
