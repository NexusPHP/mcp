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

/*
 * Shared bootstrap for the runnable examples: registers the Composer autoloader,
 * loads `ExampleLogger` (a severity-filtering STDERR logger), and installs an
 * uncaught-exception handler. Each example starts with
 * `require __DIR__.'/bootstrap.php';`.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/ExampleLogger.php';

set_exception_handler(static function (Throwable $e): void {
    new ExampleLogger()->critical('Uncaught {exception}', ['exception' => $e]);

    exit(1);
});
