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

namespace Nexus\Mcp\Core\Schema\Enum;

/**
 * The severity of a log message.
 *
 * These map to syslog message severities, as specified in RFC-5424:
 * https://datatracker.ietf.org/doc/html/rfc5424#section-6.2.1
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#logginglevel
 */
enum LoggingLevel: string
{
    /**
     * Messages that contain information normally of use only when debugging a program.
     */
    case Debug = 'debug';

    /**
     * Informational messages.
     */
    case Info = 'info';

    /**
     * Conditions that are not error conditions, but may require special handling.
     */
    case Notice = 'notice';

    /**
     * Warning messages.
     */
    case Warning = 'warning';

    /**
     * Error conditions.
     */
    case Error = 'error';

    /**
     * Critical conditions, such as hard device errors.
     */
    case Critical = 'critical';

    /**
     * A condition that should be corrected immediately, such as a corrupted system database.
     */
    case Alert = 'alert';

    /**
     * A panic condition. The system is unusable.
     */
    case Emergency = 'emergency';
}
