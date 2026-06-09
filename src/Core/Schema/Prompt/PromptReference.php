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

namespace Nexus\Mcp\Core\Schema\Prompt;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Identifies a prompt.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   type: 'ref/prompt',
 *   title?: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#promptreference
 */
final readonly class PromptReference extends BaseMetadata implements Arrayable
{
    public const string TYPE = 'ref/prompt';

    public function __construct(string $name, ?string $title = null)
    {
        parent::__construct($name, $title);

        IdentifierNameValidator::validate($name, 'prompt reference "name"');
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'prompt reference missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'prompt reference "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('name', 'prompt reference missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isString('prompt reference "name" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('prompt reference "title" must be a string or null, {type} given.');

        return new self(name: $name, title: $title);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => self::TYPE,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
