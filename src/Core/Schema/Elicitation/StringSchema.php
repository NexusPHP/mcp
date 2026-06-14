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

/**
 * Schema for a single-line string elicitation field.
 *
 * @implements PrimitiveSchemaDefinition<array{
 *   type: 'string',
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   minLength?: int<0, max>,
 *   maxLength?: int<0, max>,
 *   format?: 'date'|'date-time'|'email'|'uri',
 *   default?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#stringschema
 */
final readonly class StringSchema implements PrimitiveSchemaDefinition
{
    public const string TYPE = 'string';
    public const string FORMAT_DATE = 'date';
    public const string FORMAT_DATE_TIME = 'date-time';
    public const string FORMAT_EMAIL = 'email';
    public const string FORMAT_URI = 'uri';

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var null|'date'|'date-time'|'email'|'uri'
     */
    public ?string $format;

    /**
     * @var null|int<0, max>
     */
    public ?int $minLength;

    /**
     * @var null|int<0, max>
     */
    public ?int $maxLength;

    public function __construct(
        ?string $title = null,
        ?string $description = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $format = null,
        public ?string $default = null,
    ) {
        Assert::that($title)
            ->nullOr()
            ->isNonEmptyString('string schema "title" must be a non-empty string or null.')
        ;
        Assert::that($description)
            ->nullOr()
            ->isNonEmptyString('string schema "description" must be a non-empty string or null.')
        ;
        Assert::that($minLength)
            ->nullOr()
            ->isNaturalInt('string schema "minLength" must be a non-negative integer or null.')
        ;
        Assert::that($maxLength)
            ->nullOr()
            ->isNaturalInt('string schema "maxLength" must be a non-negative integer or null.')
        ;
        Assert::that($format)
            ->nullOr()
            ->isOneOf(
                [self::FORMAT_DATE, self::FORMAT_DATE_TIME, self::FORMAT_EMAIL, self::FORMAT_URI],
                'string schema "format" must be one of "date", "date-time", "email", "uri".',
            )
        ;

        $this->title = $title;
        $this->description = $description;
        $this->format = $format;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'string schema is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'string schema "type" must be {other}, {value} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('string schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('string schema "description" must be a string or null, {type} given.');

        $minLength = $data['minLength'] ?? null;
        Assert::that($minLength)->nullOr()->isInt('string schema "minLength" must be an int or null, {type} given.');

        $maxLength = $data['maxLength'] ?? null;
        Assert::that($maxLength)->nullOr()->isInt('string schema "maxLength" must be an int or null, {type} given.');

        $format = $data['format'] ?? null;
        Assert::that($format)->nullOr()->isString('string schema "format" must be a string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('string schema "default" must be a string or null, {type} given.');

        return new self(
            title: $title,
            description: $description,
            minLength: $minLength,
            maxLength: $maxLength,
            format: $format,
            default: $default,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['type' => self::TYPE];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->minLength) {
            $data['minLength'] = $this->minLength;
        }

        if (null !== $this->maxLength) {
            $data['maxLength'] = $this->maxLength;
        }

        if (null !== $this->format) {
            $data['format'] = $this->format;
        }

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
