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
use PHPStan\Node\Printer\ExprPrinter;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rejects a comparison holding a literal on the left and a call on the right. The `yoda_style`
 * fixer leaves call operands alone, so the convention is enforced here instead.
 *
 * @implements Rule<Node\Expr\BinaryOp>
 *
 * @internal
 */
final class NoFunctionCallYodaComparisonRule implements Rule
{
    private const array COMPARISONS = [
        Node\Expr\BinaryOp\Identical::class,
        Node\Expr\BinaryOp\NotIdentical::class,
        Node\Expr\BinaryOp\Equal::class,
        Node\Expr\BinaryOp\NotEqual::class,
        Node\Expr\BinaryOp\Smaller::class,
        Node\Expr\BinaryOp\SmallerOrEqual::class,
        Node\Expr\BinaryOp\Greater::class,
        Node\Expr\BinaryOp\GreaterOrEqual::class,
    ];
    private const array CALLS = [
        Node\Expr\FuncCall::class,
        Node\Expr\MethodCall::class,
        Node\Expr\NullsafeMethodCall::class,
        Node\Expr\StaticCall::class,
    ];

    public function __construct(private readonly ExprPrinter $exprPrinter)
    {
    }

    #[\Override]
    public function getNodeType(): string
    {
        return Node\Expr\BinaryOp::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (! \in_array($node::class, self::COMPARISONS, true)) {
            return [];
        }

        if (! self::isLiteral($node->left) || ! \in_array($node->right::class, self::CALLS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Yoda comparison against a call: `%s`. Put the call on the left of the operator.',
                $this->exprPrinter->printExpr($node),
            ))
                ->identifier('nexusMcp.functionCallYodaComparison')
                ->build(),
        ];
    }

    private static function isLiteral(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_) {
            return true;
        }

        return $expr instanceof Node\Expr\ConstFetch
            && \in_array(strtolower($expr->name->toString()), ['null', 'true', 'false'], true);
    }
}
