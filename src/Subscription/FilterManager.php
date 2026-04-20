<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Subscription;

final class FilterManager
{
    /** @var list<array{headerName: string, headerValue: string}> */
    private array $filters = [];

    public function addFilter(string $headerName, string $headerValue): void
    {
        foreach ($this->filters as $f) {
            if ($f['headerName'] === $headerName && $f['headerValue'] === $headerValue) {
                return; // Already exists
            }
        }
        $this->filters[] = ['headerName' => $headerName, 'headerValue' => $headerValue];
    }

    public function hasFilter(string $headerName, string $headerValue): bool
    {
        foreach ($this->filters as $f) {
            if ($f['headerName'] === $headerName && $f['headerValue'] === $headerValue) {
                return true;
            }
        }

        return false;
    }

    public function removeFilter(string $headerName, string $headerValue): void
    {
        $this->filters = array_values(array_filter(
            $this->filters,
            static fn(array $f) => !($f['headerName'] === $headerName && $f['headerValue'] === $headerValue),
        ));
    }

    /** @return list<array{headerName: string, headerValue: string}> */
    public function all(): array
    {
        return $this->filters;
    }

    public function hasFilters(): bool
    {
        return !empty($this->filters);
    }

    public function reset(): void
    {
        $this->filters = [];
    }
}
