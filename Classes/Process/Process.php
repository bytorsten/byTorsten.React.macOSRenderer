<?php
namespace byTorsten\React\macOSRenderer\Process;

use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files;
use React\ChildProcess\Process as ChildProcess;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\ExtendedPromiseInterface;
use React\Stream\ReadableResourceStream;
use byTorsten\React\Core\Service\FilePathResolver;
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
    protected $parameter;

    /**
     * @var string
     */
    protected $identifier;

    /**
     * @param string $identifier
     * @param array $parameter
     */
    public function __construct(string $identifier, array $parameter = [])
    {
        $this->identifier = $identifier;
        $this->parameter = $parameter;
        $this->readyDeferred = new Deferred();
    }

    /**
     * @return string
     */
    protected function buildCmd(): string
    {
        $filePathResolver = new FilePathResolver();
        $parameters = array_merge($this->parameter, ['address' => $this->getAddress(), 'threads' => $this->configuration['threads']]);
        $scriptPath = $filePathResolver->resolveFilePath($this->configuration['path']);
        return 'exec ' . $scriptPath . array_reduce(array_keys($parameters), function (string $joinedParameters, string $name) use ($parameters) {
            $value = $parameters[$name];
            if ($value === true) {
                $joinedParameters .= ' --' . $name;
            } else if ($value !== false) {
                $joinedParameters .= ' --' . $name . ' ' . $value;
            }

            return $joinedParameters;
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
     * @param LoopInterface $loop
     * @throws ProcessException
     */
    public function start(LoopInterface $loop): void
    {
        ['tempPath' => $tempPath, 'processTimeout' => $processTimeout] = $this->configuration;

        Files::createDirectoryRecursively($tempPath);

        $this->pipePaths = [
            'stdout' => Files::concatenatePaths([$tempPath, 'renderer_stdout_' . $this->identifier]),
            'stderr' => Files::concatenatePaths([$tempPath, 'renderer_stderr_' . $this->identifier])
        ];

        $this->address = 'unix://' . Files::concatenatePaths([$tempPath, 'socket_' . $this->identifier . '.sock']);
        @unlink($this->address);

        foreach ($this->pipePaths as $key => $path) {
            @unlink($path);

            if (posix_mkfifo($path, 0600) === false) {
                throw new ProcessException(sprintf('Could not create named %s pipe "%s"', $key, $path));
            }
        }

        $this->stdout = new ReadableResourceStream(fopen($this->pipePaths['stdout'], 'rn'), $loop);
        $this->stderr = new ReadableResourceStream(fopen($this->pipePaths['stderr'], 'rn'), $loop);

        $cmd = $this->buildCmd() . ' 2>' . $this->pipePaths['stderr'] . ' >' . $this->pipePaths['stdout'];

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
            @unlink($this->pipePaths['stdout']);
            @unlink($this->pipePaths['stderr']);

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
            $pid = $this->getPid();

            $this->process->terminate($force === true ? static::SIGKILL : null);
            $this->process = null;

            $this->logger->warning(sprintf('Renderer process (%s) %sterminated', $pid, $force ? 'forcefully ' :''));
        }

        if ($this->address !== null) {
            @unlink($this->address);
        }

        if ($this->pipePaths !== null) {
            foreach ($this->pipePaths as $path) {
                @unlink($path);
            }
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
