<?php

namespace Amp\PHPUnit;

use Amp\Deferred;
use Amp\Future;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Revolt\EventLoop\Loop;
use Revolt\EventLoop\Driver\TracingDriver;
use function Amp\coroutine;
use function Amp\Future\all;

abstract class AsyncTestCase extends PHPUnitTestCase
{
    private const RUNTIME_PRECISION = 2;

    private Deferred $deferred;

    private string $timeoutId;

    /** @var float Minimum runtime in seconds. */
    private float $minimumRuntime = 0;

    /** @var string Temporary storage for actual test name. */
    private string $realTestName;

    private bool $setUpInvoked = false;

    /**
     * @codeCoverageIgnore Invoked before code coverage data is being collected.
     */
    final public function setName(string $name): void
    {
        parent::setName($name);
        $this->realTestName = $name;
    }

    /**
     * Execute any needed cleanup after the test before loop watchers are checked.
     */
    protected function cleanup(): void
    {
        // Empty method in base class.
    }

    protected function setUp(): void
    {
        $this->setUpInvoked = true;
        \gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop

        $this->deferred = new Deferred;

        Loop::setErrorHandler(function (\Throwable $exception): void {
            if ($this->deferred->isComplete()) {
                return;
            }

            $this->deferred->error(new LoopCaughtException($exception));
        });
    }

    /** @internal */
    final protected function runAsyncTest(mixed ...$args): mixed
    {
        if (!$this->setUpInvoked) {
            self::fail(\sprintf(
                '%s::setUp() overrides %s::setUp() without calling the parent method',
                \str_replace("\0", '@', \get_class($this)), // replace NUL-byte in anonymous class name
                self::class
            ));
        }

        parent::setName($this->realTestName);

        $start = \microtime(true);

        try {
            try {
                [$returnValue] = all([
                    coroutine(function () use ($args): mixed {
                        try {
                            $result = ([$this, $this->realTestName])(...$args);
                            if ($result instanceof Future) {
                                $result = $result->await();
                            }
                            return $result;
                        } finally {
                            if (!$this->deferred->isComplete()) {
                                $this->deferred->complete(null);
                            }
                        }
                    }),
                    $this->deferred->getFuture()
                ]);
            } finally {
                $this->cleanup();
            }
        } finally {
            if (isset($this->timeoutId)) {
                Loop::cancel($this->timeoutId);
            }
        }

        $end = \microtime(true);

        if ($this->minimumRuntime > 0) {
            $actualRuntime = \round($end - $start, self::RUNTIME_PRECISION);
            $msg = 'Expected test to take at least %0.3fs but instead took %0.3fs';
            self::assertGreaterThanOrEqual(
                $this->minimumRuntime,
                $actualRuntime,
                \sprintf($msg, $this->minimumRuntime, $actualRuntime)
            );
        }

        return $returnValue;
    }

    final protected function runTest(): mixed
    {
        parent::setName('runAsyncTest');
        return parent::runTest();
    }

    /**
     * Fails the test if the loop does not run for at least the given amount of time.
     *
     * @param int $runtime Required run time in seconds.
     */
    final protected function setMinimumRuntime(float $runtime): void
    {
        if ($runtime < 0.001) {
            throw new \Error('Minimum runtime must be at least 0.001s');
        }

        $this->minimumRuntime = \round($runtime, self::RUNTIME_PRECISION);
    }

    /**
     * Fails the test (and stops the loop) after the given timeout.
     *
     * @param float $timeout Timeout in seconds.
     */
    final protected function setTimeout(float $timeout): void
    {
        $this->timeoutId = Loop::delay($timeout, function () use ($timeout): void {
            Loop::setErrorHandler(null);

            $additionalInfo = '';

            $driver = Loop::getDriver();
            if ($driver instanceof TracingDriver) {
                $additionalInfo .= "\r\n\r\n" . $driver->dump();
            } elseif (\class_exists(TracingDriver::class)) {
                $additionalInfo .= "\r\n\r\nSet AMP_DEBUG_TRACE_WATCHERS=true as environment variable to trace watchers keeping the loop running.";
            } else {
                $additionalInfo .= "\r\n\r\nSet REVOLT_DEBUG_TRACE_WATCHERS=true as environment variable to trace watchers keeping the loop running.";
            }

            if ($this->deferred->isComplete()) {
                return;
            }

            try {
                $this->fail(\sprintf('Expected test to complete before %0.3fs time limit%s', $timeout, $additionalInfo));
            } catch (AssertionFailedError $e) {
                $this->deferred->error($e);
            }
        });

        Loop::unreference($this->timeoutId);
    }

    /**
     * @param int $invocationCount Number of times the callback must be invoked or the test will fail.
     * @param callable|null $returnCallback Callable providing a return value for the callback.
     *
     * @return callable|MockObject Mock object having only an __invoke method.
     */
    final protected function createCallback(int $invocationCount, callable $returnCallback = null): callable
    {
        $mock = $this->createMock(CallbackStub::class);
        $invocationMocker = $mock->expects(self::exactly($invocationCount))
            ->method('__invoke');

        if ($returnCallback) {
            $invocationMocker->willReturnCallback($returnCallback);
        }

        return $mock;
    }
}
