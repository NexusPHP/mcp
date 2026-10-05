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

namespace Nexus\Mcp\Extension\Skills\Server;

use Nexus\Assert\Assert;
use Nexus\Mcp\Extension\Skills\Frontmatter;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Extension\Skills\Skills;

/**
 * A skill read from a directory on disk, holding the bytes of every file as they stood when it was built.
 */
final readonly class DirectorySkill
{
    private const string PATH_PATTERN = '/\A(?:[A-Za-z0-9][A-Za-z0-9._~-]*\/)*(?=[^\/]{1,64}\z)[a-z0-9]+(?:-[a-z0-9]+)*\z/';
    private const array MIME_TYPES = [
        'css' => 'text/css',
        'csv' => 'text/csv',
        'gif' => 'image/gif',
        'htm' => 'text/html',
        'html' => 'text/html',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'md' => 'text/markdown',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'py' => 'text/x-python',
        'sh' => 'application/x-sh',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain',
        'xml' => 'application/xml',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
    ];

    public Skill $entry;

    /**
     * URI of the skill's directory, the entry's `uri` without its `/SKILL.md` suffix.
     *
     * @var non-empty-string
     */
    public string $rootUri;

    /**
     * Keyed by URI, the `SKILL.md` first.
     *
     * @var non-empty-array<non-empty-string, SkillFile>
     */
    public array $files;

    /**
     * @param non-empty-string $path      One or more `/`-separated segments naming the skill, the last being its lowercase, hyphenated `name`
     * @param non-empty-string $directory Directory that holds the skill's `SKILL.md`
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $path, string $directory)
    {
        Assert::that($path)->matchesRegularExpression(
            self::PATH_PATTERN,
            'Skill path must be "/"-separated URI-safe segments ending in a valid skill name, {value} given.',
        );

        Assert::that(is_dir($directory))->isTrue(\sprintf('Skill directory "%s" does not exist.', $directory));

        $rootUri = Skills::URI_PREFIX.$path;
        $manifestUri = $rootUri.'/'.Skills::MANIFEST_FILENAME;
        $supportingFiles = $this->listFiles($directory, $rootUri);
        Assert::that($supportingFiles)->hasOffset($manifestUri, \sprintf('Skill directory "%s" holds no "SKILL.md".', $directory));

        $manifestFile = $this->loadFile($manifestUri, $supportingFiles[$manifestUri]);
        $files = [$manifestUri => $manifestFile];
        unset($supportingFiles[$manifestUri]);
        ksort($supportingFiles, \SORT_STRING);

        foreach ($supportingFiles as $uri => $filePath) {
            $files[$uri] = $this->loadFile($uri, $filePath);
        }

        $resources = [];

        foreach ($files as $file) {
            $resources[] = new SkillResource($file->uri, SkillResource::computeDigest($file->contents), \strlen($file->contents));
        }

        $this->rootUri = $rootUri;
        $this->files = $files;
        $this->entry = new Skill($manifestUri, Frontmatter::parse($manifestFile->contents), $resources);
    }

    /**
     * @param non-empty-string $uri
     */
    private function loadFile(string $uri, string $path): SkillFile
    {
        $contents = @file_get_contents($path);
        Assert::that($contents)->isString(\sprintf('Skill file "%s" could not be read.', $path));

        return new SkillFile(
            $uri,
            $contents,
            self::MIME_TYPES[strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? 'application/octet-stream',
        );
    }

    /**
     * The path of every regular file under the directory, keyed by the file's URI, with links and
     * dot-prefixed entries left out along with everything beneath them.
     *
     * @param non-empty-string $rootUri
     *
     * @return array<non-empty-string, string>
     */
    private function listFiles(string $directory, string $rootUri): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_SELF),
            static fn(\SplFileInfo $entry): bool => ! $entry->isLink() && ! str_starts_with($entry->getFilename(), '.'),
        ));

        foreach ($iterator as $file) {
            \assert($file instanceof \RecursiveDirectoryIterator);

            if ($file->isFile()) {
                $segments = array_map(rawurlencode(...), explode(\DIRECTORY_SEPARATOR, $file->getSubPathname()));
                $paths[$rootUri.'/'.implode('/', $segments)] = $file->getPathname();
            }
        }

        return $paths;
    }
}
