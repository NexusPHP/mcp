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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Prompt;

/**
 * The server's response to a prompts/list request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listpromptsresult
 */
final readonly class ListPromptsResult extends PaginatedResult implements ServerResult
{
    /**
     * @var list<Prompt>
     */
    public array $prompts;

    /**
     * @param list<Prompt> $prompts
     */
    public function __construct(array $prompts, ?Cursor $nextCursor = null, ?Meta $meta = null)
    {
        Assert::that($prompts)->isList('ListPromptsResult prompts must be a list, got non-list array.');

        foreach ($prompts as $prompt) {
            Assert::that($prompt)->isInstanceOf(Prompt::class);
        }

        $this->prompts = $prompts;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('prompts', 'ListPromptsResult wire data missing "prompts".');
        Assert::that($data['prompts'])->isArray('ListPromptsResult wire "prompts" must be an array, {type} given.');

        $prompts = [];

        foreach ($data['prompts'] as $entry) {
            Assert::that($entry)
                ->isArray('ListPromptsResult wire prompt entry must be an object, {type} given.')
                ->isMap('ListPromptsResult wire prompt entry must be a string-keyed object.')
            ;
            $prompts[] = Prompt::fromArray($entry);
        }

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListPromptsResult wire "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = Meta::parseFromWire($data, 'Result');

        return new self($prompts, $nextCursor, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'prompts' => array_map(static fn(Prompt $prompt): array => $prompt->toArray(), $this->prompts),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
