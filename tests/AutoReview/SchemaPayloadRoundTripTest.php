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

namespace Nexus\Mcp\Tests\AutoReview;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\Root;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the wire shape of standalone schema payload types — value objects
 * that appear inside JSON-RPC envelopes but also have meaningful identity
 * on their own. Each fixture is a hand-authored, pretty-printed JSON file
 * under `schema-payload/{Class}/{variant}.json`. The test decodes the
 * fixture, reconstructs the instance via `Arrayable::fromArray`, re-encodes
 * with `JSON_PRETTY_PRINT`, and asserts the string round-trips byte-for-byte.
 *
 * Variant convention is `N + 2`: `all-props.json`, `none.json`, plus one
 * `no-{paramName}.json` per optional constructor param.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SchemaPayloadRoundTripTest extends AbstractRoundTripTestCase
{
    public function testEveryRegisteredEntryIsArrayable(): void
    {
        $nonArrayable = [];

        foreach (self::registry() as $dir => $entry) {
            if (! is_subclass_of($entry['class'], Arrayable::class)) {
                $nonArrayable[] = \sprintf('%s (%s)', $dir, $entry['class']);
            }
        }

        self::assertSame([], $nonArrayable, \sprintf(
            'Registry entries pointing at non-Arrayable classes: %s.',
            implode(', ', $nonArrayable),
        ));
    }

    #[\Override]
    protected static function fixtureRoot(): string
    {
        return __DIR__.'/schema-payload';
    }

    /**
     * Schema payload fixture registry. Each entry binds a fixture directory
     * to the `Arrayable` class whose wire shape it pins. The class is loose
     * `class-string` because `Arrayable`'s template parameter is invariant;
     * `reconstruct()` enforces `Arrayable`-conformance at the runtime boundary.
     *
     * @return iterable<string, array{class: class-string}>
     */
    #[\Override]
    protected static function registry(): iterable
    {
        yield 'ClientCapabilities' => ['class' => ClientCapabilities::class];

        yield 'Icon' => ['class' => Icon::class];

        yield 'Implementation' => ['class' => Implementation::class];

        yield 'Meta' => ['class' => Meta::class];

        yield 'RequestMeta' => ['class' => RequestMeta::class];

        yield 'Root' => ['class' => Root::class];

        yield 'ServerCapabilities' => ['class' => ServerCapabilities::class];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $decoded
     */
    #[\Override]
    protected static function reconstruct(array $entry, array $decoded): \JsonSerializable
    {
        self::assertArrayHasKey('class', $entry);

        $class = $entry['class'];
        self::assertIsString($class);
        \assert(is_subclass_of($class, Arrayable::class));

        return $class::fromArray($decoded);
    }
}
