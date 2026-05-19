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
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The client's response to an elicitation request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#elicitresult
 */
final readonly class ElicitResult extends Result implements ClientResult
{
    /**
     * @var null|array<non-empty-string, bool|int|list<string>|string>
     */
    public ?array $content;

    /**
     * @param null|array<string, bool|int|list<string>|string> $content
     */
    public function __construct(
        public ElicitAction $action,
        ?array $content = null,
        MetaObject $meta = new MetaObject(),
    ) {
        if (null !== $content) {
            Assert::that($content)
                ->isMap('"result.content" must be a string-keyed map.')
                ->keys()->isNonEmptyString('each "result.content" key must be a non-empty string.')
            ;

            foreach ($content as $key => $value) {
                self::validateValue($key, $value);
            }
        }

        $this->content = $content;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('action', '"result" missing the required "action" key.');
        $action = EnumValueValidator::parse(ElicitAction::class, $data['action'], '"result.action"');

        $content = null;

        if (\array_key_exists('content', $data)) {
            Assert::that($data['content'])
                ->isArray('"result.content" must be an object, {type} given.')
                ->isMap('"result.content" must be a string-keyed object.')
            ;

            foreach ($data['content'] as $key => $value) {
                self::validateValue('content entry '.$key, $value);
            }

            /** @var array<string, bool|int|list<string>|string> $content */
            $content = $data['content'];
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($action, $content, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'action' => $this->action->value,
        ];

        if (null !== $this->content) {
            $data['content'] = $this->content;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function validateValue(string $context, mixed $value): void
    {
        if (\is_string($value) || \is_int($value) || \is_bool($value)) {
            return;
        }

        Assert::that($value)
            ->isList(\sprintf('"result" "%s" must be a string, int, bool, or list of strings, non-list array given.', $context))
            ->values()->isString(\sprintf('each "result" "%s" list entries must be strings, {type} given.', $context))
        ;
    }
}
