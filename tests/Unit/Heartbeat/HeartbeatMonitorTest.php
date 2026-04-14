<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Heartbeat;

use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Heartbeat\HeartbeatMonitor;
use Apntalk\EslReact\Heartbeat\IdleTimer;
use Apntalk\EslReact\Heartbeat\LivenessState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class HeartbeatMonitorTest extends AsyncTestCase
{
    public function testFirstMissDegradesAndTriggersSingleProbeBeforeDead(): void
    {
        $probeCount = 0;
        $monitor = new HeartbeatMonitor(
            HeartbeatConfig::withInterval(0.02, 0.01),
            new IdleTimer(),
            $this->loop,
        );
        $monitor->setProbeCallback(function () use (&$probeCount): void {
            $probeCount++;
        });

        $monitor->start();

        $this->waitUntil(
            fn (): bool => $monitor->state() === LivenessState::Degraded,
            0.05,
        );

        self::assertSame(1, $probeCount);

        $this->waitUntil(
            fn (): bool => $monitor->state() === LivenessState::Dead,
            0.05,
        );

        self::assertSame(1, $probeCount);
    }

    public function testRecordActivityRestoresLiveAfterDegradation(): void
    {
        $monitor = new HeartbeatMonitor(
            HeartbeatConfig::withInterval(0.02, 0.01),
            new IdleTimer(),
            $this->loop,
        );

        $monitor->start();

        $this->waitUntil(
            fn (): bool => $monitor->state() === LivenessState::Degraded,
            0.05,
        );

        $monitor->recordActivity();

        self::assertSame(LivenessState::Live, $monitor->state());

        $monitor->stop();
    }
}
