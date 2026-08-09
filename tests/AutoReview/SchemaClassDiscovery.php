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

/**
 * Cached, filtered view of the classes under `src/`.
 *
 * @internal
 */
trait SchemaClassDiscovery
{
    /**
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @return list<class-string<T>>
     */
    protected static function concreteSubclasses(string $base): array
    {
        $matches = [];

        foreach (self::sourceClasses() as $class) {
            if (! is_subclass_of($class, $base)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $matches[] = $class;
        }

        sort($matches);

        return $matches;
    }

    /**
     * @return list<class-string>
     */
    private static function sourceClasses(): array
    {
        /** @var null|list<class-string> $cache */
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $directory = realpath(__DIR__.'/../../src');
        \assert(\is_string($directory));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $classes = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class) || interface_exists($class)) {
                $classes[] = $class;
            }
        }

        $cache = $classes;

        return $cache;
    }
}
