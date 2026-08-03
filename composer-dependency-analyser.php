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

return new Configuration()
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/conformance', isDev: true)
    ->addPathToScan(__DIR__.'/examples', isDev: true)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->addPathToExclude(__DIR__.'/tests/AutoReview/data')
    // These read `SIGINT` / `SIGTERM` behind a `defined()` guard and fall back when ext-pcntl
    // is absent, so requiring the extension would over-constrain a `composer install`.
    ->ignoreErrorsOnExtensionAndPath('ext-pcntl', __DIR__.'/examples/http-server.php', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnExtensionAndPath('ext-pcntl', __DIR__.'/conformance/server.php', [ErrorType::SHADOW_DEPENDENCY])
    // A suggested dependency: JwksAccessTokenValidator guards its use behind `class_exists` and
    // names the package to install, so production code may reference it without requiring it.
    ->ignoreErrorsOnPackageAndPath('firebase/php-jwt', __DIR__.'/src/Server/Auth/JwksAccessTokenValidator.php', [ErrorType::DEV_DEPENDENCY_IN_PROD])
;
