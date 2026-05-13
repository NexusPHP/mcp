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

namespace Nexus\Mcp\Core\Schema\Resource;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Validation\Rfc6570UriTemplateValidator;

/**
 * A reference to a resource or resource template definition.
 *
 * @implements Arrayable<array{
 *   type: 'ref/resource',
 *   uri: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourcetemplatereference
 */
final readonly class ResourceTemplateReference implements Arrayable
{
    public const string TYPE = 'ref/resource';

    /**
     * @var non-empty-string
     */
    public string $uri;

    public function __construct(string $uri)
    {
        Rfc6570UriTemplateValidator::validate($uri, 'ResourceTemplateReference');

        $this->uri = $uri;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'ResourceTemplateReference data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('ResourceTemplateReference "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('uri', 'ResourceTemplateReference data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('ResourceTemplateReference "uri" must be a string, {type} given.');

        return new self($uri);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'uri' => $this->uri,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
