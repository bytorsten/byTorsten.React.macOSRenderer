<?php
namespace byTorsten\React\macOSRenderer\Process;

use byTorsten\React\Core\IPC\Process\ProxyProcessInterface;
use React\EventLoop\LoopInterface;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\FulfilledPromise;
use React\Stream\ReadableResourceStream;
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
     * @var array
     */
    protected $pipeNames;

    /**
     * @param int $pid
     * @param array $pipeNames
     */
    public function __construct(int $pid, array $pipeNames)
    {
        $this->pid = $pid;
        $this->pipeNames = $pipeNames;
    }

    /**
     * @param LoopInterface $loop
     */
    public function start(LoopInterface $loop): void
    {
        $this->stdout = new ReadableResourceStream(fopen($this->pipeNames['stdout'], 'rn'), $loop);
        $this->stderr = new ReadableResourceStream(fopen($this->pipeNames['stderr'], 'rn'), $loop);

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
        @unlink($this->pipeNames['stdout']);
        @unlink($this->pipeNames['stderr']);
        @posix_kill($this->pid, $force ? Process::SIGTERM : Process::SIGKILL);
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
}
