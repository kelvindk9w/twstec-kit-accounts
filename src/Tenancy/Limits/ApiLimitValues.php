<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

/**
 * Um valor para cada limite da API (ApiLimit).
 */
final readonly class ApiLimitValues
{
    public function __construct(
        public int $quota,
        public int $burstPerMinute,
        public int $concurrency,
    ) {}

    public function get(ApiLimit $limit): int
    {
        return match ($limit) {
            ApiLimit::Quota => $this->quota,
            ApiLimit::BurstPerMinute => $this->burstPerMinute,
            ApiLimit::Concurrency => $this->concurrency,
        };
    }

    /**
     * @return array{quota: int, burst_per_minute: int, concurrency: int}
     */
    public function toArray(): array
    {
        return [
            ApiLimit::Quota->value => $this->quota,
            ApiLimit::BurstPerMinute->value => $this->burstPerMinute,
            ApiLimit::Concurrency->value => $this->concurrency,
        ];
    }
}
