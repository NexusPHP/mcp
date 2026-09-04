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

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$component = getenv('MCP_COMPONENT_DIR');

if (! \is_string($component) || ! is_dir($component)) {
    throw new RuntimeException('MCP_COMPONENT_DIR must name a component directory under src/.');
}

$config = (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->addPathToScan($component, isDev: false)
;

// Sibling components load through the root autoloader, so the analyser cannot attribute their usage.
$siblings = match (basename($component)) {
    'Core' => [],
    'Server', 'Client' => ['nexusphp/mcp-core'],
    'Extension' => ['nexusphp/mcp-core', 'nexusphp/mcp-server', 'nexusphp/mcp-client'],
    default => throw new RuntimeException(\sprintf('Unknown component directory "%s".', $component)),
};

if ([] !== $siblings) {
    $config->ignoreErrorsOnPackages($siblings, [ErrorType::UNUSED_DEPENDENCY]);
}

match (basename($component)) {
    'Server' => $config->ignoreErrorsOnPackageAndPath('firebase/php-jwt', $component.'/Auth/JwksAccessTokenValidator.php', [ErrorType::SHADOW_DEPENDENCY]),
    'Client' => $config->ignoreErrorsOnExtensionAndPath('ext-sodium', $component.'/Auth/EncryptedFileTokenStore.php', [ErrorType::SHADOW_DEPENDENCY]),
    'Extension' => $config->ignoreErrorsOnPackageAndPath('firebase/php-jwt', $component.'/Auth/ClientAssertionSigner.php', [ErrorType::SHADOW_DEPENDENCY]),
    default => null,
};

return $config;
