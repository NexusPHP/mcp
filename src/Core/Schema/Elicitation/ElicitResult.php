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

namespace Nexus\Mcp\Core\Schema\Elicitation;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the client for an `elicitation/create` request.
 *
 * @implements InputResponse<array{
 *   action: non-empty-string,
 *   content?: array<int|non-empty-string, bool|float|int|list<string>|string>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#elicitresult
 */
final readonly class ElicitResult implements InputResponse
{
    /**
     * @param null|array<int|non-empty-string, bool|float|int|list<string>|string> $content
     */
    public function __construct(public ElicitAction $action, public ?array $content = null)
    {
        if (null !== $content) {
            Assert::that($content)->keys()->isIntOrNonEmptyString('each elicit result "content" key must be an int or non-empty string.');

            foreach ($content as $key => $value) {
                self::validateValue((string) $key, $value);
            }
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('action', 'elicit result is missing the required "action" key.');
        $action = EnumValueValidator::parse(ElicitAction::class, $data['action'], 'elicit result "action"');

        $content = null;

        if (\array_key_exists('content', $data)) {
            Assert::that($data['content'])->isArray('elicit result "content" must be an object, {type} given.');

            foreach ($data['content'] as $key => $value) {
                self::validateValue('content entry '.$key, $value);
            }

            /** @var array<int|non-empty-string, bool|float|int|list<string>|string> $content */
            $content = $data['content'];
        }

        return new self(action: $action, content: $content);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['action' => $this->action->value];

        if (null !== $this->content) {
            $data['content'] = $this->content;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if (null !== $this->content && array_is_list($this->content)) {
            $data['content'] = (object) $this->content;
        }

        return $data;
    }

    private static function validateValue(string $context, mixed $value): void
    {
        if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return;
        }

        Assert::that($value)
            ->isList(\sprintf('elicit result "%s" must be a string, int, float, bool, or list of strings, non-list array given.', $context))
            ->values()->isString(\sprintf('each elicit result "%s" list entry must be a string, {type} given.', $context))
        ;
    }
}
