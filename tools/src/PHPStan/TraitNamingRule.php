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

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rejects the `*Trait` suffix.
 *
 * @implements Rule<Node\Stmt\Trait_>
 *
 * @internal
 */
final class TraitNamingRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        // PHPStan emits `InClassNode` for the classes that use a trait, never for the declaration itself.
        return Node\Stmt\Trait_::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->namespacedName?->toString() ?? $node->name?->toString();

        if (null === $name || ! str_starts_with($name, 'Nexus\\Mcp\\')) {
            return [];
        }

        if (str_starts_with($name, 'Nexus\\Mcp\\Tests\\') || str_starts_with($name, 'Nexus\\Mcp\\Tools\\')) {
            return [];
        }

        if (! str_ends_with($name, 'Trait')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf('Trait "%s" must NOT use the *Trait suffix.', $name))
                ->identifier('nexusMcp.classNaming')
                ->build(),
        ];
    }
}
