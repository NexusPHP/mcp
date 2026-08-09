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
 * Rule rejecting an argument that assigns while it is passed, as in `foo($x = bar())`.
 *
 * @implements Rule<Node\Arg>
 *
 * @internal
 */
final class NoAssignmentInCallArgumentRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Node\Arg::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (! AssignmentFinder::findsAssignment([$node->value])) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Assignment inside a call argument. Hoist the assignment onto its own statement ahead of the call.',
            )
                ->identifier('nexusMcp.assignmentInCallArgument')
                ->build(),
        ];
    }
}
