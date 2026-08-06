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
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Answers whether an expression assigns as it is evaluated.
 *
 * @internal
 */
final class AssignmentFinder
{
    /**
     * A closure body runs later rather than during evaluation, and a call argument is reported
     * against its own call, so both bound the walk and keep each assignment reported once.
     *
     * @param list<Node> $nodes
     */
    public static function findsAssignment(array $nodes): bool
    {
        $visitor = new class extends NodeVisitorAbstract {
            public bool $found = false;

            #[\Override]
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Arg) {
                    return NodeVisitorAbstract::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) {
                    $this->found = true;

                    return NodeVisitorAbstract::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($nodes);

        return $visitor->found;
    }
}
