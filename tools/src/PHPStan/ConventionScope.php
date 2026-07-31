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
 * Decides which analysed classes each convention rule governs. PHPStan walks `conformance`,
 * `examples`, `src`, `tests` and `tools`, while the conventions apply to `src` and to test
 * classes only.
 *
 * @internal
 */
final class ConventionScope
{
    private const string PACKAGE_PREFIX = 'Nexus\\Mcp\\';
    private const string TESTS_PREFIX = 'Nexus\\Mcp\\Tests\\';
    private const string TOOLS_PREFIX = 'Nexus\\Mcp\\Tools\\';

    /**
     * A shipped class under `src/`, which the root autoloader maps to the package prefix.
     */
    public static function isSourceClass(ClassReflection $class): bool
    {
        $name = $class->getName();

        return str_starts_with($name, self::PACKAGE_PREFIX)
            && ! str_starts_with($name, self::TESTS_PREFIX)
            && ! str_starts_with($name, self::TOOLS_PREFIX);
    }

    /**
     * A PHPUnit test class under `tests/`. Fixtures live there too but extend nothing.
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
