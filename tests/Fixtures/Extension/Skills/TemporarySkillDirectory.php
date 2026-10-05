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

namespace Nexus\Mcp\Tests\Fixtures\Extension\Skills;

/**
 * A skill directory under the system temporary directory, built file by file.
 *
 * @internal
 */
final readonly class TemporarySkillDirectory
{
    /**
     * @var non-empty-string
     */
    public string $path;

    public function __construct()
    {
        $this->path = \sprintf('%s/nexus-mcp-skill-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->path, 0o700);
    }

    /**
     * @param non-empty-string $name
     *
     * @return non-empty-string The absolute path written
     */
    public function writeManifest(string $name): string
    {
        return $this->write('SKILL.md', \sprintf("---\nname: %s\ndescription: A skill.\n---\n", $name));
    }

    /**
     * @param non-empty-string $relativePath
     *
     * @return non-empty-string The absolute path written
     */
    public function write(string $relativePath, string $contents): string
    {
        $path = $this->path.'/'.$relativePath;

        if (! is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function remove(): void
    {
        $this->removeDirectory($this->path);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isDir() && ! $file->isLink()) {
                $this->removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
