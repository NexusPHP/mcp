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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#promptargument
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
        parent::__construct($name, $title);

        IdentifierNameValidator::validate($name, 'PromptArgument');
        Assert::that($description)->nullOr()->isNonEmptyString('PromptArgument description must be a non-empty string or null.');

        $this->description = $description;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'PromptArgument data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('PromptArgument "name" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('PromptArgument "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('PromptArgument "description" must be a string or null, {type} given.');

        $required = $data['required'] ?? null;
        Assert::that($required)->nullOr()->isBool('PromptArgument "required" must be a bool or null, {type} given.');

        return new self($name, $title, $description, $required);
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
