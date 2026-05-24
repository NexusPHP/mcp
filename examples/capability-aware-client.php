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

/*
 * Spawns the `stdio-server.php` example and inspects what it negotiated. The
 * client gates every typed request on the server's advertised capabilities, so
 * calling an unadvertised one (here `completion/complete`, which the example
 * server does not support) throws `ServerCapabilityNotSupportedException` before
 * anything reaches the transport. This shows how to degrade gracefully.
 *
 * Run with:
 *
 *     php examples/capability-aware-client.php
 */

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Psr\Log\NullLogger;

$client = Client::builder()
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-capability-example-client', version: '0.1.0')
    ->build()
;

$transport = new StdioClientTransport(command: [\PHP_BINARY, __DIR__.'/stdio-server.php']);

$client->connect($transport);

try {
    $client->initialize();

    $capabilities = $client->getServerCapabilities();

    fwrite(\STDOUT, "=== Negotiated server capabilities ===\n");

    $advertised = [
        'tools' => null !== $capabilities?->tools,
        'resources' => null !== $capabilities?->resources,
        'prompts' => null !== $capabilities?->prompts,
        'logging' => null !== $capabilities?->logging,
        'completions' => null !== $capabilities?->completions,
    ];

    foreach ($advertised as $capability => $present) {
        fwrite(\STDOUT, sprintf("  %-12s %s\n", $capability, $present ? 'advertised' : 'absent'));
    }

    fwrite(\STDOUT, "\n=== Attempting completion/complete (not advertised) ===\n");

    try {
        $client->complete(new PromptReference('walkthrough'), ['name' => 'audience', 'value' => 'a']);
        fwrite(\STDOUT, "    completion succeeded (unexpected for this server)\n");
    } catch (ServerCapabilityNotSupportedException $e) {
        fwrite(\STDOUT, sprintf("    skipped: %s\n", $e->getMessage()));
    }
} finally {
    $client->disconnect();
}
