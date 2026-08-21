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

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->layerPattern('Core', '/^Nexus\\\\Mcp\\\\Core\\\\/')
    ->layerPattern('Server', '/^Nexus\\\\Mcp\\\\Server\\\\/')
    ->layerPattern('Client', '/^Nexus\\\\Mcp\\\\Client\\\\/')
    ->layerPattern('Extension', '/^Nexus\\\\Mcp\\\\Extension\\\\/')
    ->ruleset([
        'Core' => [], // depends on no other layer
        'Server' => ['Core'], // depends on Core only
        'Client' => ['Core'], // depends on Core only
        'Extension' => ['Core', 'Server', 'Client'], // official extensions; nothing depends on it
    ])
    ->withPresets(Preset::YAGNI())
;
