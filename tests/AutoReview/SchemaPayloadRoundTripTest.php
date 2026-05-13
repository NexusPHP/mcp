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
 * The registry auto-discovers every concrete `Arrayable` outside the
 * wire-envelope namespaces (those are covered by
 * `JsonRpcEnvelopeRoundTripTest`). Adding a new payload class therefore
 * forces a fixture or the auto-review build fails.
 *
 * Variant convention is two files per class: `all-props.json` (every optional
 * field populated) and `none.json` (only required fields). With no optional
 * params they collapse to a single `all-props.json`.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SchemaPayloadRoundTripTest extends AbstractRoundTripTestCase
{
    /**
     * Concrete `Arrayable` classes under these namespaces ship inside a
     * JSON-RPC envelope and are exercised by `JsonRpcEnvelopeRoundTripTest`
     * (request/notification/result/error wrappers, plus the params bags
     * carried by their parent request/notification). Excluding them here
     * avoids double coverage and keeps schema-payload focused on value
     * objects with standalone identity.
     */
    private const array WIRE_ENVELOPE_NAMESPACES = [
        'Nexus\\Mcp\\Core\\Schema\\Error\\',
        'Nexus\\Mcp\\Core\\Schema\\JsonRpc\\',
        'Nexus\\Mcp\\Core\\Schema\\Notification\\',
        'Nexus\\Mcp\\Core\\Schema\\NotificationParams\\',
        'Nexus\\Mcp\\Core\\Schema\\Request\\',
        'Nexus\\Mcp\\Core\\Schema\\RequestParams\\',
        'Nexus\\Mcp\\Core\\Schema\\Result\\',
    ];

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
     * Schema payload fixture registry, auto-derived from `src/Core/Schema/`:
     * every concrete `Arrayable` outside the wire-envelope namespaces
     * appears here keyed by short class name, and each must have on-disk
     * fixtures under `schema-payload/{ShortName}/`.
     *
     * @return iterable<string, array{class: class-string}>
     */
    #[\Override]
    protected static function registry(): iterable
    {
        $entries = [];

        foreach (self::concreteSubclasses(Arrayable::class) as $class) {
            foreach (self::WIRE_ENVELOPE_NAMESPACES as $excluded) {
                if (str_starts_with($class, $excluded)) {
                    continue 2;
                }
            }

            $shortName = new \ReflectionClass($class)->getShortName();
            $entries[$shortName] = ['class' => $class];
        }

        ksort($entries);

        return $entries;
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
