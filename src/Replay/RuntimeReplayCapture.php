<?php declare(strict_types=1);

namespace Apntalk\EslReact\Replay;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Correlation\ConnectionSessionId;
use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Correlation\ReplyEnvelope;
use Apntalk\EslCore\Replay\ReplayEnvelopeFactory;

final class RuntimeReplayCapture
{
    private bool $enabled = false;

    /** @var list<ReplayCaptureSinkInterface> */
    private array $sinks = [];

    private ?ReplayEnvelopeFactory $factory = null;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setSession(ConnectionSessionId $sessionId): void
    {
        $this->factory = ReplayEnvelopeFactory::withSession($sessionId);
    }

    public function addSink(ReplayCaptureSinkInterface $sink): void
    {
        $this->sinks[] = $sink;
    }

    public function captureReplyEnvelope(ReplyEnvelope $envelope): void
    {
        if (!$this->enabled || $this->factory === null || empty($this->sinks)) {
            return;
        }
        $replayEnvelope = $this->factory->fromReplyEnvelope($envelope);
        foreach ($this->sinks as $sink) {
            try {
                $sink->capture($replayEnvelope);
            } catch (\Throwable) {
            }
        }
    }

    public function captureEventEnvelope(EventEnvelope $envelope): void
    {
        if (!$this->enabled || $this->factory === null || empty($this->sinks)) {
            return;
        }
        $replayEnvelope = $this->factory->fromEventEnvelope($envelope);
        foreach ($this->sinks as $sink) {
            try {
                $sink->capture($replayEnvelope);
            } catch (\Throwable) {
            }
        }
    }
}
