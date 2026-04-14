<?php declare(strict_types=1);

namespace Apntalk\EslReact\Replay;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Correlation\CorrelationContext;
use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Correlation\ReplyEnvelope;
use Apntalk\EslCore\Contracts\ReplyInterface;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Replay\ReplayEnvelope;
use Apntalk\EslCore\Replay\ReplayEnvelopeFactory;
use Apntalk\EslReact\Bgapi\PendingBgapiJob;
use Apntalk\EslCore\Replies\ApiReply;

final class RuntimeReplayCapture
{
    private bool $enabled;

    /** @var list<ReplayCaptureSinkInterface> */
    private readonly array $sinks;

    private ReplayEnvelopeFactory $factory;
    private int $captureSequence = 0;

    /**
     * @param list<ReplayCaptureSinkInterface> $sinks
     * @param \Closure(): array<string, string> $runtimeMetadataProvider
     */
    public function __construct(
        private readonly CorrelationContext $correlation,
        array $sinks = [],
        bool $enabled = false,
        private readonly ?\Closure $runtimeMetadataProvider = null,
    ) {
        $this->sinks = array_values($sinks);
        $this->enabled = $enabled;
        $this->factory = ReplayEnvelopeFactory::withSession($this->correlation->sessionId());
    }

    public function captureApiDispatch(string $command, string $args): void
    {
        $this->captureSynthetic(
            capturedType: 'dispatch',
            capturedName: $command,
            rawPayload: trim("api {$command} {$args}"),
            classifierContext: [
                RuntimeReplayArtifact::META_PATH => RuntimeReplayArtifact::API_DISPATCH,
                'runtime-command-type' => 'api',
            ],
            protocolFacts: $this->filterFacts([
                'command-type' => 'api',
                'command-name' => $command,
                'command-args' => $args,
            ]),
            derivedMetadata: $this->derivedMetadata(RuntimeReplayArtifact::withIdentity(
                RuntimeReplayArtifact::API_DISPATCH,
                [
                'runtime-command-type' => 'api',
                'runtime-command-name' => $command,
                'runtime-command-args' => $args,
                ],
            )),
        );
    }

    public function captureBgapiDispatch(string $command, string $args): void
    {
        $this->captureSynthetic(
            capturedType: 'dispatch',
            capturedName: $command,
            rawPayload: trim("bgapi {$command} {$args}"),
            classifierContext: [
                RuntimeReplayArtifact::META_PATH => RuntimeReplayArtifact::BGAPI_DISPATCH,
                'runtime-command-type' => 'bgapi',
            ],
            protocolFacts: $this->filterFacts([
                'command-type' => 'bgapi',
                'command-name' => $command,
                'command-args' => $args,
            ]),
            derivedMetadata: $this->derivedMetadata(RuntimeReplayArtifact::withIdentity(
                RuntimeReplayArtifact::BGAPI_DISPATCH,
                [
                'runtime-command-type' => 'bgapi',
                'runtime-command-name' => $command,
                'runtime-command-args' => $args,
                ],
            )),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function captureSubscriptionMutation(array $payload): void
    {
        $this->captureSynthetic(
            capturedType: 'dispatch',
            capturedName: RuntimeReplayArtifact::SUBSCRIPTION_MUTATE,
            rawPayload: $this->jsonPayload($payload),
            classifierContext: [
                RuntimeReplayArtifact::META_PATH => RuntimeReplayArtifact::SUBSCRIPTION_MUTATE,
                'runtime-command-type' => 'subscription',
            ],
            protocolFacts: $this->filterFacts([
                'command-type' => 'subscription',
                'mutation-kind' => (string) ($payload['mutation_kind'] ?? ''),
            ]),
            derivedMetadata: $this->derivedMetadata(RuntimeReplayArtifact::withIdentity(
                RuntimeReplayArtifact::SUBSCRIPTION_MUTATE,
                [
                    'runtime-command-type' => 'subscription',
                    'runtime-mutation-kind' => (string) ($payload['mutation_kind'] ?? ''),
                    'runtime-mutation-noop' => !empty($payload['noop']) ? 'true' : 'false',
                ],
            )),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function captureFilterMutation(array $payload): void
    {
        $this->captureSynthetic(
            capturedType: 'dispatch',
            capturedName: RuntimeReplayArtifact::FILTER_MUTATE,
            rawPayload: $this->jsonPayload($payload),
            classifierContext: [
                RuntimeReplayArtifact::META_PATH => RuntimeReplayArtifact::FILTER_MUTATE,
                'runtime-command-type' => 'filter',
            ],
            protocolFacts: $this->filterFacts([
                'command-type' => 'filter',
                'mutation-kind' => (string) ($payload['mutation_kind'] ?? ''),
                'filter-header-name' => (string) ($payload['header_name'] ?? ''),
                'filter-header-value' => (string) ($payload['header_value'] ?? ''),
            ]),
            derivedMetadata: $this->derivedMetadata(RuntimeReplayArtifact::withIdentity(
                RuntimeReplayArtifact::FILTER_MUTATE,
                [
                    'runtime-command-type' => 'filter',
                    'runtime-mutation-kind' => (string) ($payload['mutation_kind'] ?? ''),
                    'runtime-filter-header-name' => (string) ($payload['header_name'] ?? ''),
                    'runtime-filter-header-value' => (string) ($payload['header_value'] ?? ''),
                    'runtime-mutation-noop' => !empty($payload['noop']) ? 'true' : 'false',
                ],
            )),
        );
    }

    public function captureReply(ReplyInterface $reply): void
    {
        if (!$this->shouldCapture()) {
            return;
        }

        $envelope = new ReplyEnvelope($reply, $this->correlation->nextMetadataForReply($reply));
        $this->captureDecorated(
            $this->factory->fromReplyEnvelope($envelope),
            $reply instanceof ApiReply
                ? RuntimeReplayArtifact::API_REPLY
                : RuntimeReplayArtifact::COMMAND_REPLY,
        );
    }

    public function captureEventEnvelope(EventEnvelope $envelope): void
    {
        if (!$this->shouldCapture()) {
            return;
        }

        $this->captureDecorated(
            $this->factory->fromEventEnvelope($envelope),
            RuntimeReplayArtifact::EVENT_RAW,
        );
    }

    public function captureBgapiAck(PendingBgapiJob $job, ReplyInterface $reply): void
    {
        if (!$this->shouldCapture()) {
            return;
        }

        $this->captureDecorated(
            $this->factory->fromReply($reply),
            RuntimeReplayArtifact::BGAPI_ACK,
            RuntimeReplayArtifact::withIdentity(RuntimeReplayArtifact::BGAPI_ACK, [
                'runtime-command-type' => 'bgapi',
                'runtime-command-name' => $job->eslCommand(),
                'runtime-command-args' => $job->eslArgs(),
                'runtime-job-uuid' => $job->jobUuid() ?? '',
            ]),
        );
    }

    public function captureBgapiCompletion(PendingBgapiJob $job, BackgroundJobEvent $event): void
    {
        if (!$this->shouldCapture()) {
            return;
        }

        $this->captureDecorated(
            $this->factory->fromEvent($event),
            RuntimeReplayArtifact::BGAPI_COMPLETE,
            RuntimeReplayArtifact::withIdentity(RuntimeReplayArtifact::BGAPI_COMPLETE, [
                'runtime-command-type' => 'bgapi',
                'runtime-command-name' => $job->eslCommand(),
                'runtime-command-args' => $job->eslArgs(),
                'runtime-job-uuid' => $job->jobUuid() ?? '',
            ]),
        );
    }

    private function shouldCapture(): bool
    {
        return $this->enabled && $this->sinks !== [];
    }

    /**
     * @param array<string, string> $extraDerivedMetadata
     */
    private function captureDecorated(
        ReplayEnvelope $base,
        string $artifactName,
        array $extraDerivedMetadata = [],
    ): void {
        $derived = RuntimeReplayArtifact::withIdentity($artifactName, $base->derivedMetadata());
        foreach ($this->runtimeMetadata() as $key => $value) {
            $derived[$key] = $value;
        }
        foreach ($extraDerivedMetadata as $key => $value) {
            if ($value !== '') {
                $derived[$key] = $value;
            }
        }

        $envelope = new ReplayEnvelope(
            capturedType: $base->capturedType(),
            capturedName: $base->capturedName(),
            sessionId: $base->sessionId(),
            captureSequence: $this->nextCaptureSequence(),
            capturedAtMicros: $this->nowMicros(),
            protocolSequence: $base->protocolSequence(),
            rawPayload: $base->rawPayload(),
            classifierContext: $base->classifierContext(),
            protocolFacts: $base->protocolFacts(),
            derivedMetadata: $derived,
        );

        $this->emit($envelope);
    }

    /**
     * @param array<string, string> $classifierContext
     * @param array<string, string> $protocolFacts
     * @param array<string, string> $derivedMetadata
     */
    private function captureSynthetic(
        string $capturedType,
        string $capturedName,
        string $rawPayload,
        array $classifierContext,
        array $protocolFacts,
        array $derivedMetadata,
    ): void {
        if (!$this->shouldCapture()) {
            return;
        }

        $envelope = new ReplayEnvelope(
            capturedType: $capturedType,
            capturedName: $capturedName,
            sessionId: $this->correlation->sessionId()->toString(),
            captureSequence: $this->nextCaptureSequence(),
            capturedAtMicros: $this->nowMicros(),
            protocolSequence: null,
            rawPayload: $rawPayload,
            classifierContext: $classifierContext,
            protocolFacts: $protocolFacts,
            derivedMetadata: $derivedMetadata,
        );

        $this->emit($envelope);
    }

    private function emit(ReplayEnvelope $replayEnvelope): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->capture($replayEnvelope);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[esl-react] Replay capture sink exception: {$e->getMessage()}\n");
            }
        }
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function derivedMetadata(array $extra = []): array
    {
        return array_merge($this->runtimeMetadata(), $extra);
    }

    /**
     * @return array<string, string>
     */
    private function runtimeMetadata(): array
    {
        if ($this->runtimeMetadataProvider === null) {
            return [];
        }

        return ($this->runtimeMetadataProvider)();
    }

    /**
     * @param array<string, string> $facts
     * @return array<string, string>
     */
    private function filterFacts(array $facts): array
    {
        return array_filter($facts, static fn (string $value): bool => $value !== '');
    }

    private function nextCaptureSequence(): int
    {
        return ++$this->captureSequence;
    }

    private function nowMicros(): int
    {
        return (int) (microtime(true) * 1_000_000);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonPayload(array $payload): string
    {
        /** @var string $encoded */
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
