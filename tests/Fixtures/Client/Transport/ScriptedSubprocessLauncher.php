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

namespace Nexus\Mcp\Tests\Fixtures\Client\Transport;

use Amp\Process\ProcessException;
use Nexus\Mcp\Client\Transport\SubprocessInterface;
use Nexus\Mcp\Client\Transport\SubprocessLauncherInterface;

/**
 * Launcher double handing out `ScriptedSubprocess` instances.
 *
 * @phpstan-type LaunchRequest array{command: list<string>, workingDirectory: null|string, environment: array<string, string>}
 */
final class ScriptedSubprocessLauncher implements SubprocessLauncherInterface
{
    /**
     * @var list<LaunchRequest>
     */
    public array $launches = [];

    /**
     * @var list<ScriptedSubprocess>
     */
    public array $subprocesses = [];

    /**
     * @var null|LaunchRequest
     */
    private ?array $latestLaunch = null;

    private ?ScriptedSubprocess $latestSubprocess = null;

    public function __construct(private readonly ?ProcessException $failure = null)
    {
    }

    #[\Override]
    public function launch(array $command, ?string $workingDirectory, array $environment): SubprocessInterface
    {
        $this->latestLaunch = [
            'command' => $command,
            'workingDirectory' => $workingDirectory,
            'environment' => $environment,
        ];
        $this->launches[] = $this->latestLaunch;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->latestSubprocess = new ScriptedSubprocess();
        $this->subprocesses[] = $this->latestSubprocess;

        return $this->latestSubprocess;
    }

    /**
     * @return LaunchRequest
     */
    public function lastLaunch(): array
    {
        if (null === $this->latestLaunch) {
            throw new \LogicException('No launch has been requested yet.');
        }

        return $this->latestLaunch;
    }

    public function lastSubprocess(): ScriptedSubprocess
    {
        if (null === $this->latestSubprocess) {
            throw new \LogicException('No subprocess has been launched yet.');
        }

        return $this->latestSubprocess;
    }
}
