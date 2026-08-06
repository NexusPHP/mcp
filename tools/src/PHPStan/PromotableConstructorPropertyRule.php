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
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires a constructor parameter assigned unchanged to a same-named property to be promoted,
 * so a declared type lives on the parameter where callers can see it rather than on a separate
 * property declaration.
 *
 * Three shapes are deliberately left alone, because promotion would change behaviour or lose a
 * type: a parameter reassigned before it is stored, one whose stored value is transformed, and
 * one whose property type is narrower than the parameter accepts.
 *
 * @implements Rule<Node\Stmt\ClassMethod>
 *
 * @internal
 */
final class PromotableConstructorPropertyRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Node\Stmt\ClassMethod::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->toLowerString() !== '__construct' || null === $node->stmts) {
            return [];
        }

        $class = $scope->getClassReflection();

        if (null === $class || ! ConventionScope::isSourceClass($class)) {
            return [];
        }

        $unpromoted = [];

        foreach ($node->params as $param) {
            if (0 === $param->flags && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $unpromoted[$param->var->name] = true;
            }
        }

        if ([] === $unpromoted) {
            return [];
        }

        $reassigned = self::findReassignedVariables($node);
        $parameters = [];

        foreach ($class->getConstructor()->getVariants() as $variant) {
            $parameters = $variant->getParameters();

            break;
        }

        $errors = [];

        foreach (self::findIdentityAssignments($node) as $name) {
            if (! isset($unpromoted[$name]) || isset($reassigned[$name])) {
                continue;
            }

            if (! $class->hasNativeProperty($name)) {
                continue;
            }

            $propertyType = $class->getNativeProperty($name)->getReadableType();
            $parameterType = null;

            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $name) {
                    $parameterType = $parameter->getType();

                    break;
                }
            }

            // A property narrower than its parameter is the documented exception: the constructor
            // body is what narrows, and a promoted property could only restate the wider type.
            if (null === $parameterType || ! $propertyType->equals($parameterType)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Constructor parameter $%s is stored unchanged in $this->%s with the same declared type. Promote it to a constructor property.',
                $name,
                $name,
            ))
                ->identifier('nexusMcp.promotableConstructorProperty')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * Names assigned anywhere in the constructor body, which promotion would silently discard
     * because a promoted property is set before the body runs.
     *
     * @return array<string, true>
     */
    private static function findReassignedVariables(Node\Stmt\ClassMethod $constructor): array
    {
        $names = [];

        foreach (new NodeFinder()->findInstanceOf($constructor->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)) {
                $names[$assign->var->name] = true;
            }
        }

        return $names;
    }

    /**
     * Names stored verbatim as `$this->name = $name;`.
     *
     * @return list<string>
     */
    private static function findIdentityAssignments(Node\Stmt\ClassMethod $constructor): array
    {
        $names = [];

        foreach (new NodeFinder()->findInstanceOf($constructor->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            if (! $assign->var instanceof Node\Expr\PropertyFetch
                || ! $assign->var->var instanceof Node\Expr\Variable
                || 'this' !== $assign->var->var->name
                || ! $assign->var->name instanceof Node\Identifier
                || ! $assign->expr instanceof Node\Expr\Variable
                || ! \is_string($assign->expr->name)
            ) {
                continue;
            }

            if ($assign->var->name->toString() === $assign->expr->name) {
                $names[] = $assign->expr->name;
            }
        }

        return $names;
    }
}
