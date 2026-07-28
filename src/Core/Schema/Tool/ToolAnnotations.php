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

namespace Nexus\Mcp\Core\Schema\Tool;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Additional properties describing a `Tool` to clients.
 *
 * NOTE: all properties in `ToolAnnotations` are **hints**.
 * They are not guaranteed to provide a faithful description of
 * tool behavior (including descriptive properties like `title`).
 *
 * Clients should never make tool use decisions based on `ToolAnnotations`
 * received from untrusted servers.
 *
 * @implements Arrayable<array{
 *   title?: non-empty-string,
 *   readOnlyHint?: bool,
 *   destructiveHint?: bool,
 *   idempotentHint?: bool,
 *   openWorldHint?: bool,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#toolannotations
 */
final readonly class ToolAnnotations implements Arrayable
{
    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    public function __construct(
        ?string $title = null,
        public ?bool $readOnlyHint = null,
        public ?bool $destructiveHint = null,
        public ?bool $idempotentHint = null,
        public ?bool $openWorldHint = null,
    ) {
        Assert::that($title)->nullOr()->isNonEmptyString('"annotations.title" must be a non-empty string or null.');

        $this->title = $title;

        if (true === $this->readOnlyHint) {
            Assert::that($this->destructiveHint)->isNull('"annotations.destructiveHint" must be null when "readOnlyHint" is true; the spec defines it only when readOnlyHint == false.');
            Assert::that($this->idempotentHint)->isNull('"annotations.idempotentHint" must be null when "readOnlyHint" is true; the spec defines it only when readOnlyHint == false.');
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('"annotations.title" must be a string or null, {type} given.');

        $readOnlyHint = $data['readOnlyHint'] ?? null;
        Assert::that($readOnlyHint)->nullOr()->isBool('"annotations.readOnlyHint" must be a bool or null, {type} given.');

        $destructiveHint = $data['destructiveHint'] ?? null;
        Assert::that($destructiveHint)->nullOr()->isBool('"annotations.destructiveHint" must be a bool or null, {type} given.');

        $idempotentHint = $data['idempotentHint'] ?? null;
        Assert::that($idempotentHint)->nullOr()->isBool('"annotations.idempotentHint" must be a bool or null, {type} given.');

        $openWorldHint = $data['openWorldHint'] ?? null;
        Assert::that($openWorldHint)->nullOr()->isBool('"annotations.openWorldHint" must be a bool or null, {type} given.');

        return new self(
            title: $title,
            readOnlyHint: $readOnlyHint,
            destructiveHint: $destructiveHint,
            idempotentHint: $idempotentHint,
            openWorldHint: $openWorldHint,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->readOnlyHint) {
            $data['readOnlyHint'] = $this->readOnlyHint;
        }

        if (null !== $this->destructiveHint) {
            $data['destructiveHint'] = $this->destructiveHint;
        }

        if (null !== $this->idempotentHint) {
            $data['idempotentHint'] = $this->idempotentHint;
        }

        if (null !== $this->openWorldHint) {
            $data['openWorldHint'] = $this->openWorldHint;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
