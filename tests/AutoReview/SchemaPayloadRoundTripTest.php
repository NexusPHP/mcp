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
 * Round-trip gate for every concrete `Arrayable` outside the envelope namespaces.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SchemaPayloadRoundTripTest extends AbstractRoundTripTestCase
{
    private const array WIRE_ENVELOPE_NAMESPACES = [
        'Nexus\\Mcp\\Core\\Schema\\Error\\',
        'Nexus\\Mcp\\Core\\Schema\\JsonRpc\\',
        'Nexus\\Mcp\\Core\\Schema\\Notification\\',
        'Nexus\\Mcp\\Core\\Schema\\NotificationParams\\',
        'Nexus\\Mcp\\Core\\Schema\\Request\\',
        'Nexus\\Mcp\\Core\\Schema\\RequestParams\\',
        'Nexus\\Mcp\\Core\\Schema\\Result\\',
        'Nexus\\Mcp\\Core\\Schema\\ResultResponse\\',
        'Nexus\\Mcp\\Extension\\Tasks\\Schema\\Request\\',
        'Nexus\\Mcp\\Extension\\Tasks\\Schema\\RequestParams\\',
        'Nexus\\Mcp\\Extension\\Tasks\\Schema\\Result\\',
        'Nexus\\Mcp\\Extension\\Tasks\\Schema\\ResultResponse\\',
    ];
    private const array ENCODING_PATHS_DIVERGE = [
        'Annotations' => true,
        'ClientCapabilities' => true,
        'ElicitRequest' => true,
        'ElicitRequestedSchema' => true,
        'ElicitResult' => true,
        'GenericResultMetaObject' => true,
        'NotificationMetaObject' => true,
        'PayloadMetaObject' => true,
        'RequestMetaObject' => true,
        'ServerCapabilities' => true,
        'SubscriptionFilter' => true,
        'Tool' => true,
        'ToolAnnotations' => true,
        'UiResourceCsp' => true,
        'UiResourceMeta' => true,
        'UiResourcePermissions' => true,
        'UiToolMeta' => true,
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
     * @return iterable<string, array{class: class-string, encodingPathsDiverge?: bool}>
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

            $shortName = (new \ReflectionClass($class))->getShortName();
            $entry = ['class' => $class];

            if (isset(self::ENCODING_PATHS_DIVERGE[$shortName])) {
                $entry['encodingPathsDiverge'] = true;
            }

            $entries[$shortName] = $entry;
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
