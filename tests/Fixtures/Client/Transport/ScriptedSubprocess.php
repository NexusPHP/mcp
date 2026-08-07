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

use Amp\ByteStream\Pipe;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Process\ProcessException;
use Nexus\Mcp\Client\Transport\SubprocessInterface;

/**
 * In-memory subprocess whose streams and exit are driven by the test rather than by a real
 * process. Spawning a real one is impossible inside an Infection mutant, whose `file://` stream
 * wrapper makes `proc_open()` fail.
 */
final class ScriptedSubprocess implements SubprocessInterface
{
    private const int BUFFER_SIZE = 8_192;

    public int $killCount = 0;
    private readonly Pipe $stdin;
    private readonly Pipe $stdout;
    private readonly Pipe $stderr;

    /**
     * @var DeferredFuture<int>
     */
    private readonly DeferredFuture $exit;

    public function __construct(private readonly int $pid = 4_242)
    {
        $this->stdin = new Pipe(self::BUFFER_SIZE);
        $this->stdout = new Pipe(self::BUFFER_SIZE);
        $this->stderr = new Pipe(self::BUFFER_SIZE);
        $this->exit = new DeferredFuture();
    }

    #[\Override]
    public function getStdin(): WritableStream
    {
        return $this->stdin->getSink();
    }

    #[\Override]
    public function getStdout(): ReadableStream
    {
        return $this->stdout->getSource();
    }

    #[\Override]
    public function getStderr(): ReadableStream
    {
        return $this->stderr->getSource();
    }

    #[\Override]
    public function getPid(): int
    {
        return $this->pid;
    }

    #[\Override]
    public function join(?Cancellation $cancellation = null): int
    {
        return $this->exit->getFuture()->await($cancellation);
    }

    #[\Override]
    public function kill(): void
    {
        ++$this->killCount;
        $this->endOutputStreams();
    }

    /**
     * Reads whatever the transport has written to the subprocess' STDIN so far.
     */
    public function readWrittenLine(): ?string
    {
        return $this->stdin->getSource()->read();
    }

    public function emitStdout(string $bytes): void
    {
        $this->stdout->getSink()->write($bytes);
    }

    public function emitStderr(string $bytes): void
    {
        $this->stderr->getSink()->write($bytes);
    }

    public function exitWith(int $exitCode): void
    {
        $this->endOutputStreams();
        $this->exit->complete($exitCode);
    }

    public function failToReportExit(): void
    {
        $this->endOutputStreams();
        $this->exit->error(new ProcessException('Process ended without reporting a status.'));
    }

    /**
     * A real subprocess takes its pipes down with it, and the transport's stdout read loop and
     * stderr forwarder both wait on EOF to finish.
     */
    private function endOutputStreams(): void
    {
        $this->stdout->getSink()->close();
        $this->stderr->getSink()->close();
    }
}
