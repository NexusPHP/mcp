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
 * Holds a native `assert()` in `src/` to a condition carrying nothing Infection can mutate: an
 * `instanceof` or a single-argument `is_*()` call, over a plain variable or property.
 *
 * @implements Rule<Node\Expr\FuncCall>
 *
 * @internal
 */
final class NativeAssertConditionRule implements Rule
{
    public function __construct(private readonly ExprPrinter $exprPrinter)
    {
    }

    #[\Override]
    public function getNodeType(): string
    {
        return Node\Expr\FuncCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Name || $node->name->toLowerString() !== 'assert') {
            return [];
        }

        $class = $scope->getClassReflection();

        if (null === $class || ! ConventionScope::isSourceClass($class)) {
            return [];
        }

        $condition = ($node->getArgs()[0] ?? null)?->value;

        if (null === $condition || self::carriesNoMutant($condition)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '`%s` must carry an instanceof or a single-argument is_*() call over a plain variable.',
                $this->exprPrinter->printExpr($node),
            ))
                ->identifier('nexusMcp.nativeAssertCondition')
                ->tip('`zend.assertions=-1` strips the line in production and the mutation job runs that way, so Infection reports any mutant on the condition as not covered.')
                ->build(),
        ];
    }

    private static function carriesNoMutant(Node\Expr $condition): bool
    {
        if ($condition instanceof Node\Expr\Instanceof_) {
            return $condition->class instanceof Node\Name && self::isPlainOperand($condition->expr);
        }

        if (! $condition instanceof Node\Expr\FuncCall
            || ! $condition->name instanceof Node\Name
            || ! str_starts_with($condition->name->toLowerString(), 'is_')
        ) {
            return false;
        }

        $args = $condition->getArgs();
        $argument = \count($args) === 1 ? ($args[0] ?? null)?->value : null;

        return null !== $argument && self::isPlainOperand($argument);
    }

    /**
     * An operand carrying no sub-expression of its own, so nothing inside it can be mutated.
     */
    private static function isPlainOperand(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable) {
            return true;
        }

        return $expr instanceof Node\Expr\PropertyFetch
            && $expr->name instanceof Node\Identifier
            && self::isPlainOperand($expr->var);
    }
}
