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

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Extension\Skills\Client\SkillClient;
use Nexus\Mcp\Extension\Skills\Client\SkillsClientExtension;

$client = (new ClientBuilder())
    ->setLogger(new PsrLogger())
    ->setClientInfo(name: 'nexus-skills-example-client', version: '0.1.0')
    ->enableExtension(new SkillsClientExtension())
    ->build()
;

$client->connect(new StdioClientTransport(command: [\PHP_BINARY, __DIR__.'/skills-server.php']));
$skills = new SkillClient($client);

try {
    fwrite(\STDOUT, "=== skills/list: frontmatter and manifest, no file read yet ===\n");
    $listed = $skills->listSkills()->skills;

    foreach ($listed as $skill) {
        fwrite(\STDOUT, sprintf("- %s (%s)\n  %s\n", $skill->frontmatter['name'], $skill->uri, $skill->frontmatter['description']));

        foreach (is_array($skill->resources) ? $skill->resources : [] as $resource) {
            fwrite(\STDOUT, sprintf("    %s  %d bytes  %s...\n", $resource->uri, $resource->size, substr($resource->digest, 0, 19)));
        }
    }

    $releaseNotes = $listed[0] ?? null;

    if (null === $releaseNotes) {
        exit(1);
    }

    fwrite(\STDOUT, "\n=== readSkillFile: the SKILL.md, checked against its size, digest and frontmatter ===\n");
    $manifest = $skills->readSkillFile($releaseNotes, $releaseNotes->uri);
    fwrite(\STDOUT, is_string($manifest) ? $manifest : "    The server asked for input first.\n");

    fwrite(\STDOUT, "\n=== readSkillFile: a file not listed by the manifest is refused before any request ===\n");
    $rootUri = dirname($releaseNotes->uri);

    try {
        $skills->readSkillFile($releaseNotes, $rootUri.'/scripts/install.sh');
    } catch (SkillVerificationFailedException $e) {
        fwrite(\STDOUT, sprintf("    %s\n", $e->getMessage()));
    }

    fwrite(\STDOUT, "\n=== resources/directory/read: the skill's root ===\n");

    foreach ($skills->readDirectory($rootUri)->resources as $child) {
        fwrite(\STDOUT, sprintf("- %s (%s)\n", $child->uri, $child->mimeType ?? 'unknown'));
    }

    fwrite(\STDOUT, "\n=== skills/get: a skill left out of the listing, generated on each read ===\n");
    $dailyBrief = $skills->getSkill('skill://reports/daily-brief/SKILL.md');
    fwrite(\STDOUT, sprintf(
        "- %s, resources: %s\n",
        $dailyBrief->frontmatter['name'],
        is_array($dailyBrief->resources) ? 'listed' : 'dynamic, so only its frontmatter is checked',
    ));

    $brief = $skills->readSkillFile($dailyBrief, $dailyBrief->uri);
    fwrite(\STDOUT, is_string($brief) ? $brief : "    The server asked for input first.\n");
} finally {
    $client->disconnect();
}
