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
 * Describes an argument that a prompt can accept.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   required?: bool,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#promptargument
 */
final readonly class PromptArgument extends BaseMetadata implements Arrayable
{
    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    public function __construct(
        string $name,
        ?string $title = null,
        ?string $description = null,
        public ?bool $required = null,
    ) {
        parent::__construct(name: $name, title: $title);

        IdentifierNameValidator::validate($name, '"arguments.name"');
        Assert::that($description)->nullOr()->isNonEmptyString('"arguments.description" must be a non-empty string or null.');

        $this->description = $description;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', '"arguments" is missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isString('"arguments.name" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('"arguments.title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('"arguments.description" must be a string or null, {type} given.');

        $required = $data['required'] ?? null;
        Assert::that($required)->nullOr()->isBool('"arguments.required" must be a bool or null, {type} given.');

        return new self(name: $name, title: $title, description: $description, required: $required);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->required) {
            $data['required'] = $this->required;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
