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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversNothing]
#[Group('static-analysis')]
final class TypeInferenceTest extends TypeInferenceTestCase
{
    use SchemaClassDiscovery;

    #[DataProvider('provideFileAssertsCases')]
    public function testFileAsserts(mixed ...$args): void
    {
        \assert(\is_string($args[0] ?? null) && \is_string($args[1] ?? null));

        $this->assertFileAsserts($args[0], $args[1], ...\array_slice($args, 2));
    }

    public static function provideFileAssertsCases(): iterable
    {
        yield from self::gatherAssertTypesFromDirectory(__DIR__.'/data');
    }

    public function testEveryConcreteRequestHasMethodTypeAssertion(): void
    {
        self::assertEveryConcreteHasMethodAssertion(
            JsonRpcRequest::class,
            __DIR__.'/data/request-generics.php',
        );
    }

    public function testEveryConcreteNotificationHasMethodTypeAssertion(): void
    {
        self::assertEveryConcreteHasMethodAssertion(
            JsonRpcNotification::class,
            __DIR__.'/data/notification-generics.php',
        );
    }

    /**
     * Asserts the data file contains a `ShortName::getMethod()` reference for
     * every concrete subclass of the given base. A class with no entry could
     * widen its return type to `non-empty-string` unnoticed.
     *
     * @param class-string $base
     */
    private static function assertEveryConcreteHasMethodAssertion(string $base, string $dataFile): void
    {
        $contents = file_get_contents($dataFile);
        self::assertIsString($contents, \sprintf('Could not read type-inference data file "%s".', $dataFile));

        $missing = [];

        foreach (self::concreteSubclasses($base) as $class) {
            $shortName = (new \ReflectionClass($class))->getShortName();

            if (! str_contains($contents, $shortName.'::getMethod()')) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete subclasses of %s without a method() type assertion in %s: %s. Add an `assertType(\'\\\'method-name\\\'\', %s::getMethod())` line.',
            $base,
            basename($dataFile),
            implode(', ', $missing),
            'ShortName',
        ));
    }
}
