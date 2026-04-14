<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Support;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Contracts\ReplayEnvelopeInterface;

final class CollectingReplaySink implements ReplayCaptureSinkInterface
{
    /** @var list<ReplayEnvelopeInterface> */
    private array $captured = [];

    public function __construct(
        private readonly bool $throwOnCapture = false,
    ) {}

    public function capture(ReplayEnvelopeInterface $envelope): void
    {
        if ($this->throwOnCapture) {
            throw new \RuntimeException('capture sink boom');
        }

        $this->captured[] = $envelope;
    }

    /**
     * @return list<ReplayEnvelopeInterface>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    public function reset(): void
    {
        $this->captured = [];
    }
}
