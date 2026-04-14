<?php declare(strict_types=1);
namespace Apntalk\EslReact\Config;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;

final class RuntimeConfig {
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
        public readonly RetryPolicy $retryPolicy,
        public readonly HeartbeatConfig $heartbeat,
        public readonly BackpressureConfig $backpressure,
        public readonly CommandTimeoutConfig $commandTimeout,
        public readonly SubscriptionConfig $subscriptions,
        public readonly bool $replayCaptureEnabled,
        /** @var list<ReplayCaptureSinkInterface> */
        public readonly array $replayCaptureSinks,
    ) {
        if ($this->host === '') {
            throw new \InvalidArgumentException('host must not be empty');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException('port must be between 1 and 65535');
        }
        if ($this->replayCaptureEnabled && $this->replayCaptureSinks === []) {
            throw new \InvalidArgumentException('replayCaptureSinks must not be empty when replay capture is enabled');
        }
    }

    public static function create(
        string $host,
        int $port = 8021,
        string $password = 'ClueCon',
        ?RetryPolicy $retryPolicy = null,
        ?HeartbeatConfig $heartbeat = null,
        ?BackpressureConfig $backpressure = null,
        ?CommandTimeoutConfig $commandTimeout = null,
        ?SubscriptionConfig $subscriptions = null,
        bool $replayCaptureEnabled = false,
        array $replayCaptureSinks = [],
    ): self {
        return new self(
            host: $host,
            port: $port,
            password: $password,
            retryPolicy: $retryPolicy ?? RetryPolicy::default(),
            heartbeat: $heartbeat ?? HeartbeatConfig::default(),
            backpressure: $backpressure ?? BackpressureConfig::default(),
            commandTimeout: $commandTimeout ?? CommandTimeoutConfig::default(),
            subscriptions: $subscriptions ?? SubscriptionConfig::empty(),
            replayCaptureEnabled: $replayCaptureEnabled,
            replayCaptureSinks: $replayCaptureSinks,
        );
    }

    public function connectionUri(): string {
        return sprintf('tcp://%s:%d', $this->host, $this->port);
    }
}
