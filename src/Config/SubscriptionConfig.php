<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Config;

final class SubscriptionConfig
{
    /** @param list<string> $initialEventNames */
    /** @param list<array{headerName: string, headerValue: string}> $initialFilters */
    private function __construct(
        public readonly array $initialEventNames,
        public readonly bool $subscribeAll,
        public readonly array $initialFilters,
    ) {}

    public static function empty(): self
    {
        return new self(initialEventNames: [], subscribeAll: false, initialFilters: []);
    }

    public static function all(): self
    {
        return new self(initialEventNames: [], subscribeAll: true, initialFilters: []);
    }

    public static function forEvents(string ...$eventNames): self
    {
        return new self(initialEventNames: array_values($eventNames), subscribeAll: false, initialFilters: []);
    }

    public function withFilter(string $headerName, string $headerValue): self
    {
        $filters = $this->initialFilters;
        $filters[] = ['headerName' => $headerName, 'headerValue' => $headerValue];
        return new self($this->initialEventNames, $this->subscribeAll, $filters);
    }

    public function hasInitialSubscriptions(): bool
    {
        return $this->subscribeAll || count($this->initialEventNames) > 0;
    }
}
