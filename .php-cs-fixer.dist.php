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

use Nexus\CsConfig\Factory;
use Nexus\CsConfig\Ruleset\Nexus84;
use PhpCsFixer\Finder;
use PhpCsFixerCustomFixers\Fixer;
use PhpCsFixerCustomFixers\Fixers;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__.'/api/src',
        __DIR__.'/conformance',
        __DIR__.'/examples',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/tools/src',
    ])
    ->append([
        __FILE__,
        __DIR__.'/composer-dependency-analyser.php',
        __DIR__.'/structarmed.php',
        __DIR__.'/tools/generate-schema',
        __DIR__.'/tools/snapshot-spec-anchors',
    ])
;

$overrides = [
    'phpdoc_no_alias_tag' => [
        'replacements' => [
            'const' => 'var',
            'link' => 'see',
            'type' => 'var',
        ],
    ],
    'single_line_empty_body' => false,
    'yoda_style' => [
        'always_move_variable' => true,
        'equal' => true,
        'identical' => true,
        'less_and_greater' => false,
    ],
];

$options = [
    'cacheFile' => 'build/.php-cs-fixer.cache',
    'finder' => $finder,
    'customFixers' => new Fixers(),
    'customRules' => [
        Fixer\FunctionParameterSeparationFixer::name() => true,
        Fixer\NoCommentedOutCodeFixer::name() => true,
        Fixer\NoTrailingCommaInSinglelineFixer::name() => true,
        Fixer\NoUselessCommentFixer::name() => true,
        Fixer\NoUselessParenthesisFixer::name() => true,
        Fixer\NoUselessWriteVisibilityFixer::name() => true,
        Fixer\PhpUnitAssertArgumentsOrderFixer::name() => true,
        Fixer\PhpUnitNoUselessReturnFixer::name() => true,
        Fixer\PhpUnitRequiresConstraintFixer::name() => true,
        Fixer\PhpdocNoIncorrectVarAnnotationFixer::name() => true,
        Fixer\PhpdocSelfAccessorFixer::name() => true,
        Fixer\PhpdocTypesCommaSpacesFixer::name() => true,
        Fixer\PhpdocTypesTrimFixer::name() => true,
        Fixer\PhpdocVarAnnotationToAssertFixer::name() => true,
        Fixer\TypedClassConstantFixer::name() => true,
    ],
];

return Factory::create(new Nexus84(), $overrides, $options)->forLibrary(
    'the Nexus MCP SDK package',
    'John Paul E. Balandan, CPA',
    'paulbalandan@gmail.com',
    2026,
);
