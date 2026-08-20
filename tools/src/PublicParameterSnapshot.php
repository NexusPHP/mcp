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

namespace Nexus\Mcp\Tools;

/**
 * Snapshot of the public parameter names on every final class and enum outside `@internal`.
 *
 * Regenerate it as part of cutting a release, since the file records the surface a tag froze and the
 * check that reads it refuses a later rename of anything named here.
 */
final class PublicParameterSnapshot
{
    public const string SNAPSHOT_PATH = __DIR__.'/../../tests/AutoReview/data/public-parameter-names.json';
    private const string SOURCE_DIRECTORY = __DIR__.'/../../src';

    /**
     * @return array<class-string, array<non-empty-string, list<string>>>
     */
    public static function generateAndSaveSnapshot(): array
    {
        $snapshot = self::collectParameterNames();

        file_put_contents(
            self::SNAPSHOT_PATH,
            json_encode($snapshot, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n",
        );

        return $snapshot;
    }

    /**
     * @return array<class-string, array<non-empty-string, list<string>>>
     */
    private static function collectParameterNames(): array
    {
        $snapshot = [];

        foreach (self::sourceClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if (! $reflection->isFinal() || self::isMarkedInternal($reflection->getDocComment())) {
                continue;
            }

            $methods = [];

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if ($method->isInternal() || self::isMarkedInternal($method->getDocComment())) {
                    continue;
                }

                $parameters = $method->getParameters();

                if ([] === $parameters) {
                    continue;
                }

                $methods[$method->getName()] = array_map(
                    static fn(\ReflectionParameter $p): string => $p->getName(),
                    $parameters,
                );
            }

            if ([] === $methods) {
                continue;
            }

            ksort($methods);
            $snapshot[$class] = $methods;
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @return list<class-string>
     */
    private static function sourceClasses(): array
    {
        $directory = realpath(self::SOURCE_DIRECTORY);

        if (! \is_string($directory)) {
            throw new \RuntimeException('Could not resolve the "src" directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
        );

        $classes = [];

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    private static function isMarkedInternal(false|string $docComment): bool
    {
        return \is_string($docComment) && str_contains($docComment, '@internal');
    }
}
