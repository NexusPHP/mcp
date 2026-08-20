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

use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Refuses a rename of any public parameter the last release froze on a final class or enum.
 *
 * `composer bc:check` routes a class that was final in the released version through a reduced check
 * set omitting `ParameterNameChanged`, so this is the only gate over that surface. It reads the
 * snapshot `composer bc:snapshot` writes at release time, which is why a symbol added since the last
 * release is absent here and renaming it is correctly not a break.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class PublicParameterNameTest extends AbstractMcpTestCase
{
    private const string SNAPSHOT_PATH = __DIR__.'/data/public-parameter-names.json';

    /**
     * @param class-string $class
     * @param list<string> $releasedNames
     */
    #[DataProvider('provideReleasedParameterNamesSurviveCases')]
    public function testReleasedParameterNamesSurvive(string $class, string $method, array $releasedNames): void
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            self::markTestSkipped(\sprintf('"%s::%s()" no longer exists, which `composer bc:check` reports as a removal.', $class, $method));
        }

        $currentNames = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod($class, $method))->getParameters(),
        );

        self::assertSame($releasedNames, \array_slice($currentNames, 0, \count($releasedNames)), \sprintf(
            'The released parameter names of "%s::%s()" must stay in place and in order, since PHP 8 named arguments make each one part of the signature and `composer bc:check` cannot see a rename on a final class. Appending a parameter is fine.',
            $class,
            $method,
        ));
    }

    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function provideReleasedParameterNamesSurviveCases(): iterable
    {
        foreach (self::readSnapshot() as $class => $methods) {
            foreach ($methods as $method => $parameterNames) {
                yield \sprintf('%s::%s()', $class, $method) => [$class, $method, $parameterNames];
            }
        }
    }

    public function testSnapshotCoversTheReleasedSurface(): void
    {
        self::assertFileExists(self::SNAPSHOT_PATH, 'Run `composer bc:snapshot` to write the snapshot.');
        self::assertNotSame([], self::readSnapshot(), 'The snapshot is empty. Run `composer bc:snapshot`.');
    }

    /**
     * @return array<class-string, array<non-empty-string, list<string>>>
     */
    private static function readSnapshot(): array
    {
        $contents = file_get_contents(self::SNAPSHOT_PATH);
        \assert(\is_string($contents));

        /** @var array<class-string, array<non-empty-string, list<string>>> $snapshot */
        $snapshot = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $snapshot;
    }
}
