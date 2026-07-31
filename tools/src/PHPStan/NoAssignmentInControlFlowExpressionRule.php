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
 * Rejects an assignment mixed into a control-flow expression: `return $x = …;` and
 * `while`/`if`/`elseif`/`foreach` headers that assign while they test.
 *
 * @implements Rule<Node\Stmt>
 *
 * @internal
 */
final class NoAssignmentInControlFlowExpressionRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $keyword = self::resolveKeyword($node);

        if (null === $keyword) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Assignment inside a `%s` expression. Split the assignment onto its own statement.',
                $keyword,
            ))
                ->identifier('nexusMcp.assignmentInControlFlow')
                ->build(),
        ];
    }

    /**
     * The offending keyword, or null when the statement is clean.
     */
    private static function resolveKeyword(Node\Stmt $node): ?string
    {
        // A `return` only offends when the assignment IS the returned expression. One nested inside a
        // call argument is a separate concern the fixer does not police.
        if ($node instanceof Node\Stmt\Return_) {
            return self::isAssignment($node->expr) ? 'return' : null;
        }

        return match (true) {
            $node instanceof Node\Stmt\While_ => AssignmentFinder::findsAssignment([$node->cond]) ? 'while' : null,
            $node instanceof Node\Stmt\If_ => AssignmentFinder::findsAssignment([$node->cond]) ? 'if' : null,
            $node instanceof Node\Stmt\ElseIf_ => AssignmentFinder::findsAssignment([$node->cond]) ? 'elseif' : null,
            $node instanceof Node\Stmt\Foreach_ => AssignmentFinder::findsAssignment(
                array_values(array_filter([$node->expr, $node->keyVar, $node->valueVar])),
            ) ? 'foreach' : null,
            default => null,
        };
    }

    private static function isAssignment(?Node $node): bool
    {
        return $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp;
    }
}
