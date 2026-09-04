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
 * Stager for the docs site: copies `docs/` into the build tree, rewriting repository-escaping links
 * to GitHub URLs and refusing links whose local target does not exist.
 */
final class DocsSiteStager
{
    private const string GITHUB_BLOB = 'https://github.com/NexusPHP/mcp/blob/1.x/';
    private const string GITHUB_TREE = 'https://github.com/NexusPHP/mcp/tree/1.x/';
    private const string LINK_PATTERN = '#\]\(([^)\s<>]+)\)#';

    /**
     * @return int the process exit code
     */
    public static function run(string $root, string $stagingDir = 'build/pages-src'): int
    {
        $docs = $root.'/docs';

        if (! is_dir($docs)) {
            fwrite(\STDERR, \sprintf("No docs/ directory under \"%s\".\n", $root));

            return 1;
        }

        $staging = $root.'/'.$stagingDir;
        self::removeDirectory($staging);

        $errors = [];
        $rewritten = 0;
        $staged = 0;

        foreach (self::findFiles($docs) as $path) {
            $relative = substr($path, \strlen($docs) + 1);
            $destination = $staging.'/'.('README.md' === $relative ? 'index.md' : $relative);
            self::createDirectory(\dirname($destination));

            if (! str_ends_with($relative, '.md')) {
                if (! copy($path, $destination)) {
                    throw new \RuntimeException(\sprintf('Could not copy "%s" into the staging directory.', $relative));
                }

                ++$staged;

                continue;
            }

            $markdown = file_get_contents($path);

            if (false === $markdown) {
                throw new \RuntimeException(\sprintf('Could not read "docs/%s".', $relative));
            }

            $markdown = self::rewriteLinks($markdown, \dirname('docs/'.$relative), $root, 'docs/'.$relative, $rewritten, $errors);

            if (file_put_contents($destination, $markdown) === false) {
                throw new \RuntimeException(\sprintf('Could not write "%s" into the staging directory.', $relative));
            }

            ++$staged;
        }

        foreach ($errors as $error) {
            fwrite(\STDERR, \sprintf("\033[31m%s\033[0m\n", $error));
        }

        if ([] !== $errors) {
            fwrite(\STDERR, "\n\033[31mEvery link above escapes docs/ but has no matching file in the repository.\033[0m\n");

            return 1;
        }

        $config = self::stageConfig($root, \dirname($stagingDir));

        printf(
            "%d files staged into %s, %d repository-escaping links rewritten to GitHub URLs, site config written to %s.\n",
            $staged,
            $stagingDir,
            $rewritten,
            $config,
        );

        return 0;
    }

    /**
     * Writes a copy of `mkdocs.yml` into the build directory with its paths made relative to it.
     */
    private static function stageConfig(string $root, string $buildDir): string
    {
        $source = $root.'/mkdocs.yml';
        $config = file_get_contents($source);

        if (false === $config) {
            throw new \RuntimeException('Could not read "mkdocs.yml".');
        }

        $relocated = preg_replace(
            \sprintf('#^(docs_dir|site_dir): %s/#m', preg_quote($buildDir, '#')),
            '$1: ',
            $config,
            -1,
            $count,
        );

        if (! \is_string($relocated) || 2 !== $count) {
            throw new \RuntimeException(\sprintf('Expected "mkdocs.yml" to declare docs_dir and site_dir under "%s/".', $buildDir));
        }

        $destination = \sprintf('%s/%s/mkdocs.yml', $root, $buildDir);

        if (file_put_contents($destination, $relocated) === false) {
            throw new \RuntimeException(\sprintf('Could not write "%s/mkdocs.yml".', $buildDir));
        }

        return $buildDir.'/mkdocs.yml';
    }

    /**
     * @return list<string>
     */
    private static function findFiles(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $errors
     */
    private static function rewriteLinks(string $markdown, string $pageDir, string $root, string $page, int &$rewritten, array &$errors): string
    {
        return (string) preg_replace_callback(
            self::LINK_PATTERN,
            static function (array $match) use ($pageDir, $root, $page, &$rewritten, &$errors): string {
                $target = $match[1];

                if (str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) === 1) {
                    return $match[0];
                }

                $fragment = '';
                $hash = strpos($target, '#');

                if (false !== $hash) {
                    $fragment = substr($target, $hash);
                    $target = substr($target, 0, $hash);
                }

                $path = self::resolvePath($pageDir, $target);

                if (null === $path) {
                    $errors[] = \sprintf('%s: link "%s" climbs past the repository root.', $page, $match[1]);

                    return $match[0];
                }

                if ('docs' === $path || str_starts_with($path, 'docs/')) {
                    return $match[0];
                }

                if (is_dir($root.'/'.$path)) {
                    ++$rewritten;

                    return ']('.self::GITHUB_TREE.$path.')';
                }

                if (is_file($root.'/'.$path)) {
                    ++$rewritten;

                    return ']('.self::GITHUB_BLOB.$path.$fragment.')';
                }

                $errors[] = \sprintf('%s: link "%s" points at the missing repository path "%s".', $page, $match[1], $path);

                return $match[0];
            },
            $markdown,
        );
    }

    /**
     * Collapses `.` and `..` segments without touching the filesystem, or `null` when the path
     * climbs past the repository root.
     */
    private static function resolvePath(string $baseDir, string $target): ?string
    {
        $resolved = [];

        foreach (explode('/', $baseDir.'/'.rtrim($target, '/')) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $resolved) {
                    return null;
                }

                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        return implode('/', $resolved);
    }

    private static function createDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Could not create the directory "%s".', $directory));
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
