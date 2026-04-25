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

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversNothing]
#[Group('stan')]
final class TypeInferenceTest extends TypeInferenceTestCase
{
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
}
