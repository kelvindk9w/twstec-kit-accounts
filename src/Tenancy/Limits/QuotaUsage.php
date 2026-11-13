<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

/**
 * Como está a cota da conta depois de contar esta requisição — o que vai nos
 * cabeçalhos RateLimit-Limit / RateLimit-Remaining / RateLimit-Reset.
 */
final readonly class QuotaUsage
{
    public function __construct(
        public int $limit,
        public int $used,
        public int $resetInSeconds,
    ) {}

    public function exceeded(): bool
    {
        return $this->used > $this->limit;
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * @return array<string, int>
     */
    public function headers(): array
    {
        return [
            'RateLimit-Limit' => $this->limit,
            'RateLimit-Remaining' => $this->remaining(),
            'RateLimit-Reset' => $this->resetInSeconds,
        ];
    }
}
