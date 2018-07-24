<?php
namespace byTorsten\React\macOSRenderer\Process;

use byTorsten\React\Core\Service\FilePathResolver;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Algorithms;
use Neos\Utility\Files;
use React\ChildProcess\Process as ChildProcess;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Stream\ReadableResourceStream;
use byTorsten\React\Core\IPC\Process\ProcessException;
use byTorsten\React\Core\IPC\Process\AbstractBaseProcess;
use byTorsten\React\Core\IPC\Process\ProcessInterface;

class Process extends AbstractBaseProcess implements ProcessInterface
{
    /**
     * @Flow\InjectConfiguration
     * @var array
     */
    protected $configuration;

    /**
     * @var ChildProcess
     */
    protected $process;

    /**
     * @var Deferred
     */
    protected $readyDeferred;

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
     * @var array
     */
    protected $parameter;

    /**
     * Process constructor.
     * @param array $parameter
     */
    public function __construct(array $parameter = [])
    {
        $this->parameter = $parameter;
        $this->readyDeferred = new Deferred();
    }

    /**
     * @return string
     */
    protected function buildCmd(): string
    {
        $filePathResolver = new FilePathResolver();
        $scriptPath = $filePathResolver->resolveFilePath($this->configuration['path']);
        return 'exec ' . $scriptPath . array_reduce(array_keys($this->parameter), function (string $parameters, string $name) {
                $value = $this->parameter[$name];
                if ($value === true) {
                    $parameters .= ' --' . $name;
                } else if ($value !== false) {
                    $parameters .= ' --' . $name . ' ' . $value;
                }

                return $parameters;
            }, '');
    }

    /**
     * @return int|null
     */
    public function getPid(): ?int
    {
        return $this->process ? $this->process->getPid() : null;
    }

    /**
     * @return array
     */
    public function getPipeNames(): array
    {
        return $this->pipeNames;
    }

    /**
     * @param LoopInterface $loop
     * @throws ProcessException
     */
    public function start(LoopInterface $loop): void
    {
        ['pipes' => $pipes, 'processTimeout' => $processTimeout] = $this->configuration;

        $hash = Algorithms::generateRandomString(8);

        $this->pipeNames = [
            'stdout' => Files::concatenatePaths([sys_get_temp_dir(), $pipes['stdout'] . '_' . $hash]),
            'stderr' => Files::concatenatePaths([sys_get_temp_dir(), $pipes['stderr'] . '_' . $hash])
        ];

        foreach ($this->pipeNames as $key => $path) {
            if (posix_mkfifo($path, 0600) === false) {
                throw new ProcessException(sprintf('Could not create named %s pipe "%s"', $key, $path));
            }
        }

        $this->stdout = new ReadableResourceStream(fopen($this->pipeNames['stdout'], 'rn'), $loop);
        $this->stderr = new ReadableResourceStream(fopen($this->pipeNames['stderr'], 'rn'), $loop);

        $cmd = $this->buildCmd() . ' 2>' . $this->pipeNames['stderr'] . ' >' . $this->pipeNames['stdout'];

        $process = new ChildProcess($cmd);
        $process->start($loop);

        if ($processTimeout > 0) {
            $startupTimer = $loop->addTimer($processTimeout, function () use ($process, $processTimeout) {
                $process->terminate(static::SIGKILL);
                throw new ProcessException(sprintf('Process was unable to start in %s seconds', $processTimeout));
            });
        } else {
            $startupTimer = null;
        }

        $this->stderr->on('data', function ($chunk) use (&$errors) {
            if (strpos($chunk, 'ExperimentalWarning') === false) {
                $this->errors[] = $chunk;

                $this->logger->error($chunk);
            }
        });

        $this->stdout->on('data', function ($chunk) use ($startupTimer, $loop) {
            if (strpos($chunk, static::READY_FLAG) === 0) {
                $this->readyDeferred->resolve();

                if ($startupTimer !== null) {
                    $loop->cancelTimer($startupTimer);
                }
            } else {
                $this->logger->info($chunk);
                $this->emit('data', [$chunk]);
            }
        });

        $process->on('exit', function (int $exitCode = null) use (&$errors, $loop, $startupTimer) {
            @unlink($this->pipeNames['stdout']);
            @unlink($this->pipeNames['stderr']);

            if ($startupTimer !== null) {
                $loop->cancelTimer($startupTimer);
            }

            if ($exitCode !== null && $exitCode > 0) {
                if (!$this->emitErrors()) {
                    $error = new ProcessException('Render process stopped with exit code ' . $exitCode . '.');
                    $this->emit('error', [$error]);
                }
            }
        });

        $this->process = $process;
    }

    /**
     * @param bool $force
     */
    public function stop(bool $force = false): void
    {
        if ($this->process instanceof ChildProcess) {
            $this->process->terminate($force ? static::SIGKILL : null);
        }
    }

    /**
     *
     */
    public function detach(): void
    {
    }

    /**
     * @return ExtendedPromiseInterface
     */
    public function ready(): ExtendedPromiseInterface
    {
        return $this->readyDeferred->promise();
    }

    /**
     * @return bool
     */
    public function keepAlive(): bool
    {
        return $this->configuration['keepAlive'];
    }
}
