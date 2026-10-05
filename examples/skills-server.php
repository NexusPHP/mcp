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

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\SkillProviderInterface;
use Nexus\Mcp\Extension\Skills\Server\SkillsServerExtension;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

use function Amp\async;
use function Amp\trapSignal;

/**
 * A skill left out of `skills/list`, whose `SKILL.md` is written afresh on every read.
 */
final class DailyBriefSkill implements SkillProviderInterface
{
    private const string ROOT_URI = 'skill://reports/daily-brief';
    private const string MANIFEST_URI = self::ROOT_URI.'/SKILL.md';
    private const string DESCRIPTION = 'Summarise what changed since yesterday. Use when the user asks for a daily brief.';

    #[Override]
    public function findSkill(string $uri, ServerContext $context): ?Skill
    {
        if (self::MANIFEST_URI !== $uri) {
            return null;
        }

        return new Skill($uri, ['name' => 'daily-brief', 'description' => self::DESCRIPTION], Skills::DYNAMIC_RESOURCES);
    }

    #[Override]
    public function findFile(string $uri, ServerContext $context): ?SkillFile
    {
        if (self::MANIFEST_URI !== $uri) {
            return null;
        }

        return new SkillFile($uri, sprintf(
            "---\nname: daily-brief\ndescription: %s\n---\n\n# Daily brief\n\nCover the changes merged since %s.\n",
            self::DESCRIPTION,
            (new DateTimeImmutable('yesterday'))->format('Y-m-d'),
        ), 'text/markdown');
    }

    #[Override]
    public function findDirectory(string $uri, ServerContext $context): ?array
    {
        if (self::ROOT_URI !== $uri) {
            return null;
        }

        return [new Resource('daily-brief', self::MANIFEST_URI, description: self::DESCRIPTION, mimeType: 'text/markdown')];
    }
}

$logger = new PsrLogger();

$server = (new ServerBuilder())
    ->setLogger($logger)
    ->setServerInfo(name: 'nexus-skills-example', version: '0.1.0')
    ->setResourceStore(new ResourceStore())
    ->enableExtension(new SkillsServerExtension(new SkillStore(
        [new DirectorySkill('release-notes', __DIR__.'/skills/release-notes')],
        new DailyBriefSkill(),
        logger: $logger,
    )))
    ->build()
;

$transport = new StdioServerTransport(logger: $logger);

if (defined('SIGINT')) {
    async(static function () use ($transport): void {
        trapSignal([\SIGINT, \SIGTERM], reference: false);
        $transport->close();
    });
}

$server->run($transport);
