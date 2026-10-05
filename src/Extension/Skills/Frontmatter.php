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

namespace Nexus\Mcp\Extension\Skills;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reader and comparator for the YAML frontmatter at the start of a `SKILL.md`.
 *
 * @internal
 *
 * @see https://agentskills.io/specification
 */
final class Frontmatter
{
    private const string BLOCK_PATTERN = '/\A(?:\xEF\xBB\xBF)?---[ \t]*\R(?<yaml>.*?)^---[ \t]*\r?$/ms';

    /**
     * The number of values, containers included, that a frontmatter block may expand to once its aliases are resolved.
     */
    private const int MAX_VALUES = 10_000;

    /**
     * @return array{name: non-empty-string, description: non-empty-string, ...<string, mixed>}
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $markdown): array
    {
        SuggestedDependencyGuard::verify(self::class, Yaml::class, 'symfony/yaml');

        if (preg_match(self::BLOCK_PATTERN, $markdown, $matches) !== 1) {
            throw new \InvalidArgumentException('"SKILL.md" must begin with YAML frontmatter.');
        }

        $budget = self::MAX_VALUES;

        try {
            $frontmatter = self::render(Yaml::parse($matches['yaml'], Yaml::PARSE_DATETIME | Yaml::PARSE_OBJECT_FOR_MAP), $budget);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('"SKILL.md" frontmatter is not valid YAML: %s', $e->getMessage()), previous: $e);
        }

        Assert::that($frontmatter)
            ->isArray('"SKILL.md" frontmatter must be a mapping, {type} given.')
            ->isMap('"SKILL.md" frontmatter must be a mapping.')
            ->hasOffset('name', '"SKILL.md" frontmatter is missing the required "name" field.')
            ->hasOffset('description', '"SKILL.md" frontmatter is missing the required "description" field.')
        ;
        Assert::that($frontmatter['name'])->isNonEmptyString('"SKILL.md" frontmatter "name" must be a non-empty string, {type} given.');
        Assert::that($frontmatter['description'])->isNonEmptyString('"SKILL.md" frontmatter "description" must be a non-empty string, {type} given.');

        return $frontmatter;
    }

    /**
     * Whether two frontmatter values encode to the same JSON, key order and the list-or-object form of a
     * collection that could be either aside.
     */
    public static function equals(mixed $left, mixed $right): bool
    {
        return json_encode(self::sortKeys($left), \JSON_PARTIAL_OUTPUT_ON_ERROR) === json_encode(self::sortKeys($right), \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The parsed YAML in its JSON form: a date as text, a mapping as an array, and a mapping whose array form
     * would encode as a list kept as an object.
     *
     * @param int $budget The number of values still allowed, counted down across the whole block
     *
     * @throws \InvalidArgumentException
     */
    private static function render(mixed $value, int &$budget): mixed
    {
        if (--$budget < 0) {
            throw new \InvalidArgumentException(\sprintf('"SKILL.md" frontmatter holds more than %d values.', self::MAX_VALUES));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($value->format('H:i:sP') === '00:00:00+00:00' ? 'Y-m-d' : \DATE_ATOM);
        }

        $render = static function (mixed $item) use (&$budget): mixed {
            return self::render($item, $budget);
        };

        if ($value instanceof \stdClass) {
            $map = array_map($render, get_object_vars($value));

            return array_is_list($map) ? (object) $map : $map;
        }

        return \is_array($value) ? array_map($render, $value) : $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (! \is_array($value)) {
            return $value;
        }

        $sorted = array_map(self::sortKeys(...), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
