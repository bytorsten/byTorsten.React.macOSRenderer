<?php
namespace byTorsten\React\macOSRenderer\Process;

use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\FulfilledPromise;
use React\Stream\ReadableResourceStream;
use byTorsten\React\Core\IPC\Process\ProxyProcessInterface;
use byTorsten\React\Core\IPC\Process\AbstractBaseProcess;

class ProxyProcess extends AbstractBaseProcess implements ProxyProcessInterface
{
    /**
     * @var int
     */
    protected $pid;

    /**
     * @var ReadableResourceStream
     */
    protected $stdout;

    /**
     * @var ReadableResourceStream
     */
    protected $stderr;

    /**
     * @param int $pid
     * @param array $pipePaths
     * @param string $socketPath
     */
    public function __construct(int $pid, array $pipePaths, string $socketPath)
    {
        $this->pid = $pid;
        $this->pipePaths = $pipePaths;
        $this->socketPath = $socketPath;
    }

    /**
     * @param LoopInterface $loop
     */
    public function start(LoopInterface $loop): void
    {
        $this->stdout = new ReadableResourceStream(fopen($this->pipePaths['stdout'], 'rn'), $loop);
        $this->stderr = new ReadableResourceStream(fopen($this->pipePaths['stderr'], 'rn'), $loop);

        $this->stdout->on('data', function ($chunk) {
            if (strpos($chunk, static::READY_FLAG) === false) {
                $this->logger->info($chunk);
            }
        });

        $this->stderr->on('data', function ($chunk) use (&$errors) {
            if (strpos($chunk, 'ExperimentalWarning') === false) {
                $this->errors[] = $chunk;
                $this->logger->error($chunk);
            }
        });
    }

    /**
     * @param bool $force
     */
    public function stop(bool $force = false): void
    {
        $this->detach();
        @posix_kill($this->pid, $force ? Process::SIGTERM : Process::SIGKILL);
        @unlink($this->pipePaths['stdout']);
        @unlink($this->pipePaths['stderr']);
        @unlink($this->socketPath);
    }

    /**
     *
     */
    public function detach(): void
    {
        if ($this->stdout !== null) {
            $this->stdout->close();
            $this->stderr->close();

            $this->stdout = null;
            $this->stderr = null;
        }
    }

    /**
     * @return ExtendedPromiseInterface
     */
    public function ready(): ExtendedPromiseInterface
    {
        return new FulfilledPromise();
    }

    /**
     * @return bool
     */
    public function keepAlive(): bool
    {
        return true;
    }
}
