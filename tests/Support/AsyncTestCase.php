<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

abstract class AsyncTestCase extends TestCase
{
    protected LoopInterface $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new StreamSelectLoop();
    }

    /**
     * @return mixed
     */
    protected function await(PromiseInterface $promise, float $timeoutSeconds = 1.0): mixed
    {
        $settled = false;
        $resolved = false;
        $value = null;
        $error = null;

        $promise->then(
            function (mixed $result) use (&$settled, &$resolved, &$value): void {
                $settled = true;
                $resolved = true;
                $value = $result;
                $this->loop->stop();
            },
            function (\Throwable $e) use (&$settled, &$error): void {
                $settled = true;
                $error = $e;
                $this->loop->stop();
            },
        );

        $timer = $this->loop->addTimer($timeoutSeconds, function () use (&$error, $timeoutSeconds): void {
            $error = new \RuntimeException(sprintf('Timed out after %.2f seconds waiting for promise settlement', $timeoutSeconds));
            $this->loop->stop();
        });

        if (!$settled && $error === null) {
            $this->loop->run();
        }
        $this->loop->cancelTimer($timer);

        if (!$settled && $error === null) {
            throw new \RuntimeException('Promise did not settle');
        }

        if ($error !== null) {
            throw $error;
        }

        return $resolved ? $value : null;
    }
}
