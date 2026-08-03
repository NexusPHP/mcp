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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery;

use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Attribute\AsCompletion;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\ServerContext;

/**
 * Source object whose `#[AsCompletion]` methods exercise the completion discovery branches.
 */
final class CompletionHandlers
{
    /**
     * @param string $tone The desired tone.
     */
    #[AsPrompt(name: 'compose')]
    public function compose(string $tone = 'neutral'): string
    {
        return \sprintf('Write in a %s tone.', $tone);
    }

    /**
     * @return list<string>
     */
    #[AsCompletion(argument: 'tone', prompt: 'compose')]
    public function completeTone(string $value, ServerContext $context): array
    {
        $matches = [];

        foreach (['formal', 'friendly', 'neutral'] as $tone) {
            if (str_starts_with($tone, $value)) {
                $matches[] = $tone;
            }
        }

        return $matches;
    }

    /**
     * @param null|array<string, string> $resolved
     */
    #[AsCompletion(argument: 'path', uriTemplate: 'file:///{path}')]
    #[AsCompletion(argument: 'topic', prompt: 'compose')]
    public function completeAnywhere(string $value, ?array $resolved): CompleteResult
    {
        return new CompleteResult(completion: ['values' => [$value], 'hasMore' => false]);
    }
}
