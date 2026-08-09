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

namespace Nexus\Mcp\Tools\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPUnit\Framework\TestCase;

/**
 * Scope predicates for the `src` and test classes the convention rules govern.
 *
 * @internal
 */
final class ConventionScope
{
    private const string PACKAGE_PREFIX = 'Nexus\\Mcp\\';
    private const string TESTS_PREFIX = 'Nexus\\Mcp\\Tests\\';
    private const string TOOLS_PREFIX = 'Nexus\\Mcp\\Tools\\';

    public static function isSourceClass(ClassReflection $class): bool
    {
        $name = $class->getName();

        return str_starts_with($name, self::PACKAGE_PREFIX)
            && ! str_starts_with($name, self::TESTS_PREFIX)
            && ! str_starts_with($name, self::TOOLS_PREFIX);
    }

    /**
     * Fixtures live under `tests/` too, so `TestCase` ancestry is what tells a test class apart.
     */
    public static function isTestClass(ClassReflection $class): bool
    {
        return str_starts_with($class->getName(), self::TESTS_PREFIX)
            && $class->getNativeReflection()->isSubclassOf(TestCase::class);
    }

    public static function isSchemaClass(ClassReflection $class): bool
    {
        return str_starts_with($class->getName(), self::PACKAGE_PREFIX.'Core\\Schema\\');
    }
}
