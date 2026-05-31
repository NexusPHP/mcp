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

namespace Nexus\Mcp\Api;

use ApiGen\Index\NamespaceIndex;
use ApiGen\Info\ClassLikeInfo;
use ApiGen\Renderer\Filter;

final class RendererFilter extends Filter
{
    #[\Override]
    public function filterNamespacePage(NamespaceIndex $namespace): bool
    {
        foreach ($namespace->children as $child) {
            if ($this->filterNamespacePage($child)) {
                return true;
            }
        }

        foreach ($namespace->class as $class) {
            if ($this->filterClassLikePage($class)) {
                return true;
            }
        }

        foreach ($namespace->interface as $interface) {
            if ($this->filterClassLikePage($interface)) {
                return true;
            }
        }

        foreach ($namespace->trait as $trait) {
            if ($this->filterClassLikePage($trait)) {
                return true;
            }
        }

        foreach ($namespace->enum as $enum) {
            if ($this->filterClassLikePage($enum)) {
                return true;
            }
        }

        foreach ($namespace->exception as $exception) {
            if ($this->filterClassLikePage($exception)) {
                return true;
            }
        }

        foreach ($namespace->function as $function) {
            if ($this->filterFunctionPage($function)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function filterClassLikePage(ClassLikeInfo $classLike): bool
    {
        return self::isClassRendered($classLike);
    }

    private static function isClassRendered(ClassLikeInfo $classLike): bool
    {
        // `primary` is false for classes the analyzer filtered out, including `@internal` ones.
        return $classLike->primary && str_starts_with($classLike->name->full, 'Nexus\\Mcp\\');
    }
}
