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

namespace Nexus\Mcp\Extension\Skills\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Parameters for a `resources/directory/read` request.
 *
 * @extends RequestParams<array{
 *   _meta: template-type<RequestMetaObject, MetaObject, 'T'>,
 *   uri: non-empty-string,
 *   cursor?: non-empty-string,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class ReadResourceDirectoryRequestParams extends RequestParams
{
    /**
     * @param non-empty-string $uri URI of the directory resource, written without a trailing slash
     */
    public function __construct(
        public string $uri,
        RequestMetaObject $meta,
        public ?Cursor $cursor = null,
    ) {
        Assert::that($uri)->isNonEmptyString('"params.uri" must be a non-empty string.');

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', '"params" is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('"params.uri" must be a non-empty string, {type} given.');

        $cursor = null;

        if (\array_key_exists('cursor', $data)) {
            $raw = $data['cursor'];
            Assert::that($raw)->isNonEmptyString('"params.cursor" must be a non-empty string, {type} given.');
            $cursor = new Cursor(cursor: $raw);
        }

        Assert::that($data)->hasOffset('_meta', '"params" is missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->not()->isNonEmptyList('"params._meta" must be a string-keyed object.')
        ;

        return new self(uri: $uri, meta: RequestMetaObject::fromArray($data['_meta']), cursor: $cursor);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            '_meta' => $this->meta->toArray(),
            'uri' => $this->uri,
        ];

        if (null !== $this->cursor) {
            $data['cursor'] = $this->cursor->cursor;
        }

        return $data;
    }
}
