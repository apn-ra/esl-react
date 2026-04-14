<?php declare(strict_types=1);
namespace Apntalk\EslReact\Config;

final class CommandTimeoutConfig {
    private function __construct(
        public readonly float $apiTimeoutSeconds,
        public readonly float $bgapiAckTimeoutSeconds,
        public readonly float $subscriptionTimeoutSeconds,
        public readonly float $bgapiOrphanTimeoutSeconds,
    ) {
        if ($this->apiTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('apiTimeoutSeconds must be positive');
        }
        if ($this->bgapiAckTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('bgapiAckTimeoutSeconds must be positive');
        }
        if ($this->subscriptionTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('subscriptionTimeoutSeconds must be positive');
        }
        if ($this->bgapiOrphanTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('bgapiOrphanTimeoutSeconds must be positive');
        }
    }

    public static function default(): self {
        return new self(
            apiTimeoutSeconds: 30.0,
            bgapiAckTimeoutSeconds: 10.0,
            subscriptionTimeoutSeconds: 10.0,
            bgapiOrphanTimeoutSeconds: 120.0,
        );
    }

    public static function withApiTimeout(float $seconds): self {
        $d = self::default();
        return new self($seconds, $d->bgapiAckTimeoutSeconds, $d->subscriptionTimeoutSeconds, $d->bgapiOrphanTimeoutSeconds);
    }

    public function withBgapiOrphanTimeout(float $seconds): self {
        return new self($this->apiTimeoutSeconds, $this->bgapiAckTimeoutSeconds, $this->subscriptionTimeoutSeconds, $seconds);
    }
}
