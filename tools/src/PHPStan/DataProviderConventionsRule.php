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
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces the naming and return shape of PHPUnit data providers: a `provide*Cases` name, a
 * native `iterable` return type, and a `@return` naming both the case key and the argument shape.
 *
 * @implements Rule<InClassMethodNode>
 *
 * @internal
 */
final class DataProviderConventionsRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! ConventionScope::isTestClass($class)) {
            return [];
        }

        $method = $node->getOriginalNode();

        if (! $method->isPublic() || ! $method->isStatic() || ! str_starts_with($method->name->toString(), 'provide')) {
            return [];
        }

        return [
            ...self::checkNaming($method),
            // The type-inference data files drive PHPStan itself, so their providers yield file paths
            // rather than case shapes.
            ...str_ends_with($class->getName(), 'TypeInferenceTest') ? [] : self::checkReturnShape($method),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function checkNaming(Node\Stmt\ClassMethod $method): array
    {
        $name = $method->name->toString();

        if (preg_match('/^provide[A-Z]\S+Cases$/', $name) === 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Data provider "%s()" must match `/^provide[A-Z]\S+Cases$/`.',
                $name,
            ))
                ->identifier('nexusMcp.dataProviderNaming')
                ->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function checkReturnShape(Node\Stmt\ClassMethod $method): array
    {
        $name = $method->name->toString();
        $returnType = $method->getReturnType();

        if (! $returnType instanceof Node\Identifier || $returnType->toLowerString() !== 'iterable') {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Data provider "%s()" must declare `iterable` as its native return type.',
                    $name,
                ))
                    ->identifier('nexusMcp.dataProviderReturnType')
                    ->build(),
            ];
        }

        $docComment = $method->getDocComment()?->getText();

        if (null === $docComment || preg_match('/@return iterable<(?:class-)?string(?:<\S+>)?, array\{/', $docComment) !== 1) {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Data provider "%s()" must carry a `@return` naming the case key and argument shape, e.g. `iterable<string, array{string}>`.',
                    $name,
                ))
                    ->identifier('nexusMcp.dataProviderReturnType')
                    ->build(),
            ];
        }

        return [];
    }
}
